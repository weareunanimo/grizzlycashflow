<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\CategoryTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ordem das categorias: quem tem filho primeiro (alfabético), filhas logo
 * abaixo (alfabético) e, por último, as soltas (alfabético). A ordem é sempre
 * recalculada — nunca depende de um campo de posição mantido à mão.
 */
final class CategoryTreeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Sem CategorySeeder: estes testes montam a árvore que querem observar.
        $this->user = User::factory()->create();
    }

    public function test_it_orders_parents_then_their_children_then_loose_categories(): void
    {
        $tecnologia = $this->category('Tecnologia');
        $animais = $this->category('Animais');

        $this->category('Software', $tecnologia);
        $this->category('Hardware', $tecnologia);
        $this->category('Gatos', $animais);
        $this->category('Cachorros', $animais);

        $this->category('Finanças');
        $this->category('Alimentação');
        $this->category('Esportes');

        $this->assertSame([
            'Animais',
            'Cachorros',
            'Gatos',
            'Tecnologia',
            'Hardware',
            'Software',
            'Alimentação',
            'Esportes',
            'Finanças',
        ], $this->names());
    }

    /** Acento não pode jogar a categoria para o fim da lista. */
    public function test_it_sorts_accented_names_as_if_they_had_no_accent(): void
    {
        $this->category('Zoológico');
        $this->category('Água');
        $this->category('Alimentação');

        $this->assertSame(['Água', 'Alimentação', 'Zoológico'], $this->names());
    }

    public function test_a_top_level_category_without_children_counts_as_loose(): void
    {
        $moradia = $this->category('Moradia');
        $this->category('Aluguel', $moradia);
        $this->category('Zelador'); // topo, sem filhos

        $this->assertSame(['Moradia', 'Aluguel', 'Zelador'], $this->names());

        $tree = CategoryTree::ordered($this->user->id);
        $this->assertTrue($tree[0]->is_parent);
        $this->assertSame(1, $tree[0]->child_count);
        $this->assertFalse($tree[2]->is_parent);
    }

    public function test_depth_marks_children(): void
    {
        $parent = $this->category('Animais');
        $this->category('Gatos', $parent);

        $tree = CategoryTree::ordered($this->user->id);

        $this->assertSame(0, $tree[0]->depth);
        $this->assertSame(1, $tree[1]->depth);
    }

    public function test_archived_categories_stay_out(): void
    {
        $this->category('Ativa');
        $id = $this->category('Arquivada');
        DB::table('categories')->where('id', $id)->update(['archived_at' => now()]);

        $this->assertSame(['Ativa'], $this->names());
    }

    public function test_it_only_sees_the_current_users_categories(): void
    {
        $this->category('Minha');
        $other = User::factory()->create();
        DB::table('categories')->insert([
            'user_id' => $other->id, 'parent_id' => null, 'name' => 'Alheia', 'slug' => 'alheia',
            'kind' => 'expense', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(['Minha'], $this->names());
    }

    // ---------------------------------------------------------------- tela

    public function test_it_creates_a_top_level_category(): void
    {
        $this->actingAs($this->user)
            ->post('/categorias', ['name' => 'Academia'])
            ->assertRedirect(route('categories.index'));

        $this->assertDatabaseHas('categories', [
            'user_id' => $this->user->id, 'name' => 'Academia', 'parent_id' => null, 'slug' => 'academia',
        ]);
    }

    public function test_it_creates_a_child_category(): void
    {
        $parent = $this->category('Saúde');

        $this->actingAs($this->user)
            ->post('/categorias', ['name' => 'Dentista', 'parent_id' => $parent])
            ->assertRedirect();

        $this->assertDatabaseHas('categories', ['name' => 'Dentista', 'parent_id' => $parent]);
    }

    /** Uma nova filha aparece direto no lugar certo, sem reordenar nada. */
    public function test_a_new_child_lands_in_the_right_position(): void
    {
        $animais = $this->category('Animais');
        $this->category('Gatos', $animais);
        $this->category('Alimentação');

        $this->actingAs($this->user)->post('/categorias', ['name' => 'Cachorros', 'parent_id' => $animais]);

        $this->assertSame(['Animais', 'Cachorros', 'Gatos', 'Alimentação'], $this->names());
    }

    /** Dar um pai a uma categoria solta a move para dentro da árvore. */
    public function test_moving_a_category_under_a_parent_repositions_it(): void
    {
        $tecnologia = $this->category('Tecnologia');
        $this->category('Software', $tecnologia);
        $hardware = $this->category('Hardware');

        $this->assertSame(['Tecnologia', 'Software', 'Hardware'], $this->names());

        $this->actingAs($this->user)
            ->patch('/categorias/'.$hardware, ['name' => 'Hardware', 'parent_id' => $tecnologia])
            ->assertRedirect();

        $this->assertSame(['Tecnologia', 'Hardware', 'Software'], $this->names());
    }

    public function test_removing_the_parent_sends_the_category_back_to_the_loose_group(): void
    {
        $animais = $this->category('Animais');
        $gatos = $this->category('Gatos', $animais);
        $this->category('Cachorros', $animais);

        $this->actingAs($this->user)
            ->patch('/categorias/'.$gatos, ['name' => 'Gatos', 'parent_id' => ''])
            ->assertRedirect();

        $this->assertSame(['Animais', 'Cachorros', 'Gatos'], $this->names());
    }

    public function test_renaming_repositions_the_category(): void
    {
        $this->category('Alimentação');
        $zoo = $this->category('Zoológico');

        $this->actingAs($this->user)->patch('/categorias/'.$zoo, ['name' => 'Abelhas']);

        $this->assertSame(['Abelhas', 'Alimentação'], $this->names());
    }

    /** Só dois níveis: uma filha não pode virar mãe. */
    public function test_a_child_cannot_be_used_as_a_parent(): void
    {
        $animais = $this->category('Animais');
        $gatos = $this->category('Gatos', $animais);

        $this->actingAs($this->user)
            ->post('/categorias', ['name' => 'Siameses', 'parent_id' => $gatos])
            ->assertSessionHasErrors('parent_id');

        $this->assertDatabaseMissing('categories', ['name' => 'Siameses']);
    }

    public function test_a_category_with_children_cannot_receive_a_parent(): void
    {
        $animais = $this->category('Animais');
        $this->category('Gatos', $animais);
        $tecnologia = $this->category('Tecnologia');
        $this->category('Software', $tecnologia);

        $this->actingAs($this->user)
            ->patch('/categorias/'.$animais, ['name' => 'Animais', 'parent_id' => $tecnologia])
            ->assertSessionHasErrors('parent_id');

        $this->assertNull(DB::table('categories')->where('id', $animais)->value('parent_id'));
    }

    public function test_a_category_cannot_be_its_own_parent(): void
    {
        $id = $this->category('Animais');

        $this->actingAs($this->user)
            ->patch('/categorias/'.$id, ['name' => 'Animais', 'parent_id' => $id])
            ->assertSessionHasErrors('parent_id');
    }

    public function test_it_refuses_a_parent_from_another_user(): void
    {
        $other = User::factory()->create();
        $foreign = DB::table('categories')->insertGetId([
            'user_id' => $other->id, 'parent_id' => null, 'name' => 'Alheia', 'slug' => 'alheia',
            'kind' => 'expense', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->post('/categorias', ['name' => 'Minha', 'parent_id' => $foreign])
            ->assertNotFound();
    }

    public function test_it_cannot_edit_another_users_category(): void
    {
        $other = User::factory()->create();
        $foreign = DB::table('categories')->insertGetId([
            'user_id' => $other->id, 'parent_id' => null, 'name' => 'Alheia', 'slug' => 'alheia',
            'kind' => 'expense', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->patch('/categorias/'.$foreign, ['name' => 'Invadida'])
            ->assertNotFound();

        $this->assertDatabaseHas('categories', ['id' => $foreign, 'name' => 'Alheia']);
    }

    /** UNIQUE(user_id, parent_id, slug): dois nomes iguais no mesmo nível. */
    public function test_two_categories_with_the_same_name_do_not_collide(): void
    {
        $this->actingAs($this->user)->post('/categorias', ['name' => 'Extras'])->assertRedirect();
        $this->actingAs($this->user)->post('/categorias', ['name' => 'Extras'])->assertRedirect();

        $this->assertSame(2, DB::table('categories')->where('name', 'Extras')->count());
    }

    public function test_a_child_inherits_the_kind_of_its_parent(): void
    {
        $income = $this->category('Entradas', null, 'income');

        $this->actingAs($this->user)->post('/categorias', ['name' => 'Bônus', 'parent_id' => $income]);

        $this->assertSame('income', DB::table('categories')->where('name', 'Bônus')->value('kind'));
    }

    public function test_the_page_lists_the_tree_in_order(): void
    {
        $animais = $this->category('Animais');
        $this->category('Gatos', $animais);
        $this->category('Alimentação');

        $this->actingAs($this->user)->get('/categorias')
            ->assertOk()
            ->assertSeeInOrder(['Animais', 'Gatos', 'Alimentação']);
    }

    /** O dropdown da revisão segue a mesma ordem da tela. */
    public function test_the_review_dropdown_follows_the_same_order(): void
    {
        $animais = $this->category('Animais');
        $this->category('Gatos', $animais);
        $this->category('Alimentação');

        $labels = array_column(CategoryTree::options($this->user->id), 'label');

        $this->assertSame(['Animais', '— Gatos', 'Alimentação'], $labels);
    }

    /** @return list<string> */
    private function names(): array
    {
        return array_map(fn (object $c) => $c->name, CategoryTree::ordered($this->user->id));
    }

    private function category(string $name, ?int $parentId = null, string $kind = 'expense'): int
    {
        static $n = 0;
        $n++;

        return DB::table('categories')->insertGetId([
            'user_id' => $this->user->id,
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => 'slug-'.$n,
            'kind' => $kind,
            'is_system' => false,
            'is_essential' => false,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
