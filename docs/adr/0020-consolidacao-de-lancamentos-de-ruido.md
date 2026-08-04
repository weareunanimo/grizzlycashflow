# ADR-0020 — Consolidação de lançamentos de ruído (rendimento automático)

- **Status:** 🟡 proposta — **preferência do PO decide**
- **Data:** 2026-08-04

## Contexto

No extrato real de 2 meses: **42 das 110 linhas (38%) são `Rendimento automático`**, somando
**R$ 18,63** no período — a maioria de poucos centavos (R$ 0,04, R$ 0,09, R$ 0,13…).

Importadas como transações normais, essas linhas:
- ocupam 38% da lista de movimentações com informação irrelevante, empurrando o que importa para baixo;
- inflam contagens ("312 transações este mês") e distorcem "ticket médio";
- consomem cascata de categorização e crescem o banco sem retorno;
- aparecem no detector de recorrência como padrão diário e no heatmap como atividade todo dia.

O sistema é para ser usado **todo dia**. Uma lista onde 4 de cada 10 linhas são centavos de rendimento
é uma lista que se para de ler — e um sistema que não se lê não é usado.

Ao mesmo tempo, o rendimento **não pode ser descartado**: é receita real, afeta o saldo, e a cadeia de
saldo (ADR-0019) exige que toda linha seja contabilizada.

## Opções

| Opção | Prós | Contras |
|-------|------|---------|
| **A) Consolidar por mês na importação: uma transação `Rendimento automático — ago/2026` por conta, com as linhas originais preservadas no `document` e no `import_rows`** ✅ | lista limpa; valor mensal correto; saldo íntegro; dado bruto recuperável para sempre; 1 linha em vez de 21 | a data da transação passa a ser o último dia do mês (competência mensal), não o dia exato |
| B) Importar tudo e ocultar na UI por filtro padrão | fidelidade total ao extrato | 42 linhas/2 meses = ~5 mil em 10 anos de linhas de centavos; ainda poluem contagens e agregações se algum filtro esquecer |
| C) Importar tudo com `excluded_from_analytics = 1` | fidelidade + análises limpas | continua poluindo a lista, que é a tela mais usada |
| D) Descartar | lista limpíssima | quebra a cadeia de saldo e perde receita real — **inaceitável** |

## Decisão proposta

**Opção A**, generalizada como um recurso de perfil de importador, não um caso especial do XP:

```jsonc
// importer_profiles.options
"consolidate_rules": [
  {
    "match": { "description_regex": "^Rendimento autom[áa]tico" },
    "group_by": "month",
    "as_description": "Rendimento automático — {mês}",
    "category_slug": "rendimentos",
    "keep_source_rows": true
  }
]
```

- Uma transação consolidada por (conta, mês, regra), com `source = 'csv'` e
  `notes = "consolidação de 21 lançamentos"`.
- As linhas originais **permanecem** em `import_rows` e no `document` — auditáveis e reprocessáveis.
- A validação de cadeia de saldo roda **antes** da consolidação, sobre as linhas originais.
- Reimportar o mês atualiza a transação consolidada (idempotente, ADR-0018).
- A UI mostra "21 lançamentos consolidados — ver detalhes" que expande a lista original.
- Configurável e **desligável** por conta.

Candidatos naturais à mesma regra: tarifas de centavos, arredondamentos de cashback, IOF fracionado.

## Consequências

- Regra nova no pipeline entre `normalize` e `reconcile` (`Consolidator`), aplicada só quando o perfil pede.
- Invariante nova: **I14 — a soma das transações importadas (consolidadas ou não) é igual à soma das
  linhas originais do arquivo.** Consolidação nunca pode mudar o total.
- Sem isso, a tela principal degrada com o uso — que é o oposto do objetivo do projeto.

## ⚠️ Precisa da sua decisão

Você quer ver os 21 rendimentos diários de agosto como linhas separadas, ou uma linha
"Rendimento automático — ago/2026 · R$ 9,84" que expande quando você clicar?
