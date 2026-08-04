# Registro de Decisões Arquiteturais (ADR)

Formato: **Contexto → Opções com prós e contras → Decisão → Consequências.**

Status: 🟢 aprovada · 🟡 proposta (aguarda aprovação do PO) · 🔵 aceita por padrão (baixo risco, reversível) · ⚪ futura · 🔴 rejeitada

> **Convenção:** ADRs de 0001 a 0017 vivem neste arquivo (foram todas tomadas na mesma sessão de
> arquitetura). **Toda decisão nova a partir daqui recebe arquivo próprio** — `docs/adr/0018-titulo.md` —
> e é adicionada ao índice do `PROJECT.md`. Uma decisão revertida não é apagada: cria-se um novo ADR
> que a substitui, com o motivo. O histórico é a memória.

---

## ADR-0001 — Monólito modular com domínio isolado 🔵

**Contexto:** sistema de uso pessoal, um desenvolvedor, hospedagem compartilhada, vida esperada de 10 anos.

| Opção | Prós | Contras |
|-------|------|---------|
| **Monólito modular com domínio isolado** ✅ | um deploy; regras testáveis sem infra; permite extrair módulo depois; roda em host barato | exige disciplina de camadas (mitigado por teste arquitetural) |
| Monólito "clássico" (lógica em controllers) | rápido de começar | apodrece em 1 ano; regra de parcela impossível de testar; foi assim que morreram outros projetos financeiros |
| Microserviços | escala independente | absurdo para 1 usuário; não roda em host compartilhado |
| Framework full-stack (Laravel) | produtividade, ecossistema | domínio acoplado ao framework; upgrade major a cada 12 meses vira dívida; peso em host compartilhado |

**Decisão:** monólito modular, domínio puro em PHP, infra como plugin, **regra de dependência
verificada por teste automatizado**.

**Consequências:** ~15% mais código inicial (interfaces, DTOs). Em troca: trocar banco, provedor de IA
ou hospedagem não toca regra de negócio; testes de domínio rodam em milissegundos sem banco.

> **Nota (ADR-0005, 2026-08-04):** a linha "Framework full-stack (Laravel)" acima foi rejeitada como
> **padrão de arquitetura geral** (lógica de negócio dentro do framework) — não como escolha de
> ferramenta. O ADR-0005 aprovou Laravel depois, mas **confinado à camada de infraestrutura**
> (`Infrastructure/`/`Http/`), com `Domain/`/`Application/` permanecendo PHP puro e um teste
> automatizado proibindo `Illuminate\*` de vazar para dentro deles. As duas decisões não se
> contradizem: esta ADR decide a *forma* (monólito modular com domínio isolado); o ADR-0005 decide a
> *ferramenta* usada só na camada de fora.

---

## ADR-0002 — Fila de trabalhos em MySQL drenada por cron 🟢 **aprovada em 2026-08-04**

**Contexto:** processar PDF com IA leva 5–40 s. Não pode acontecer no request. Hospedagem
compartilhada **não permite daemon**.

| Opção | Prós | Contras |
|-------|------|---------|
| **Fila em MySQL + cron de 1 min** ✅ | zero infra nova; funciona no host atual; transacional junto com os dados; retry/watchdog simples; migração para worker real é trocar 1 classe | latência de até 60 s para começar a processar; cron de 1 min pode não existir em todo host |
| Processar no próprio request | zero infra, resultado imediato | timeout de 30–60 s do PHP-FPM; usuário travado; upload de fatura grande quebra |
| Redis / Beanstalkd | fila de verdade, baixa latência | exige VPS = infra paga (o PO pediu explicitamente para evitar) |
| Serviço externo (SQS etc.) | gerenciado | custo, latência de rede, dependência externa para função essencial |

**Decisão proposta:** fila em MySQL, claim atômico por `UPDATE ... LIMIT`, cron de 1 minuto,
watchdog de jobs travados, backoff exponencial, **fallback de "kick" via `fastcgi_finish_request()`**
se o host não oferecer cron.

**Consequências:** UI faz polling e mostra progresso real por etapa (isso passa a ser requisito de UX,
não detalhe). Latência de até 1 min é aceitável para importar fatura; **não** seria aceitável para
captura por texto/voz — por isso esses caminhos têm processamento síncrono no caminho rápido
(parser determinístico) e só caem na fila quando precisam de IA.

**Aprovado pelo PO em 2026-08-04.**

---

## ADR-0003 — Postura de privacidade e provedor de IA 🟢 **aprovada em 2026-08-04**

**Contexto:** para ler fatura escaneada, print de notificação e frase em português, precisamos de um
modelo que não roda em hospedagem compartilhada. Isso significa **enviar dado financeiro para fora**.

| Opção | Prós | Contras |
|-------|------|---------|
| **A) Nuvem com redação de PII** (recomendada) | capacidade máxima; custo ~US$ 0,30/mês; CPF/CNPJ/cartão/chave PIX mascarados antes do envio | valores, datas e nomes de estabelecimento saem do servidor (é o dado necessário para a função) |
| B) Nuvem sem redação | ligeiramente mais simples | expõe documentos completos sem necessidade |
| C) Zero nuvem (só determinístico) | privacidade total; custo zero | **sem OCR de print, sem fatura escaneada, sem voz por servidor**; classificação cai de ~97% para ~85%; muito mais digitação manual — contraria o objetivo central do PO |
| D) Modelo local em VPS com GPU | privacidade + capacidade | US$ 100+/mês; contraria a restrição de custo |

**Decisão proposta:** **A** — Claude API (`claude-haiku-4-5` para classificação, `claude-sonnet-5`
para extração de documento e visão), com `PiiRedactor` sempre no caminho, retenção zero solicitada
ao provedor, chave em `.env`, teto de gasto mensal, e **`NullProvider` permitindo operar como opção C
a qualquer momento com um flag**.

**Consequências:** o sistema tem um "modo privado" real e reversível. A escolha não é permanente:
`AiProvider` é interface, e o front não sabe quem classificou.

**Aprovado pelo PO em 2026-08-04.**

---

## ADR-0004 — Compra parcelada como entidade de primeira classe 🟢 **aprovada em 2026-08-04**

**Contexto:** o objetivo nº 1 é ver parcelas futuras. Como modelar?

| Opção | Prós | Contras |
|-------|------|---------|
| **A) `card_purchases` + `card_installments` separados de `transactions`** ✅ | parcela futura é obrigação, não fato consumado — não poluí o extrato; `group_key` reconhece a compra entre faturas; projeção é 1 query indexada; parcela tem estado próprio (`projected/billed/paid/canceled`) | 2 tabelas a mais; sincronizar parcela ↔ transação quando realizada |
| B) Tudo em `transactions` com `parent_id` + `status='projected'` | uma tabela | **toda** query de saldo, gasto por categoria e dashboard precisa lembrar de excluir projeções — uma esquecida e o saldo mente; sem lugar natural para "restam 7 parcelas" |
| C) Gerar as 12 transações reais na importação | simples de exibir | contraria o requisito do PO (nada de gerar lançamento); vira lixo quando o CSV traz o pagamento real |
| D) Calcular parcelas em tempo real, sem persistir | zero tabelas | impossível corrigir uma parcela individual; impossível marcar cancelamento; recalcular a cada dashboard |

**Decisão proposta:** **A**. `first_reference_month` derivado (`mês_da_fatura − (n−1)`) + `group_key`
são o mecanismo que faz "5/12" de agosto e "6/12" de setembro resolverem para a **mesma compra**,
sem intervenção do usuário. Invariante I10 protege isso em teste.

**Consequências:** é a decisão mais estrutural do projeto. Se mudar depois, migração de dados grande.
Por isso está aqui para aprovação.

**Aprovado pelo PO em 2026-08-04.**

---

## ADR-0005 — Framework e dependências 🟢 **aprovada em 2026-08-04 (revisada — decisão final é Laravel)**

| Opção | Prós | Contras |
|-------|------|---------|
| Slim 4 + PHP-DI + PDO + Phinx | ~5 pacotes pequenos e maduros; sem mágica; upgrade indolor | escreveríamos nós validação, autorização, sessão (≈1 semana extra na Fase 0) |
| **Laravel** ✅ | produtividade alta desde o dia 1; auth, sessão, validação, filas, e-mail e migrações já prontos; ecossistema maduro | upgrade major anual do framework em geral; peso maior em host compartilhado se mal configurado; Eloquent tende a puxar regra de negócio para o model se não for contido |
| Symfony completo | robusto, arquitetura séria | verboso, curva alta, pesado para 1 usuário |
| Zero dependência (tudo próprio) | controle total | reescrever router/validação/migrações é tempo gasto em problema já resolvido |

**Decisão final (revisada em relação à proposta original):** **Laravel**, com duas condições que
preservam a longevidade do sistema — sem elas, a decisão reabriria os riscos R09/R10 do documento de
riscos:

1. **Laravel fica confinado à camada de infraestrutura (`Infrastructure/` e `Http/`).** `Domain/` e
   `Application/` (ver ADR-0001) continuam PHP puro, **zero `use Illuminate\...`**. Persistência usa o
   Query Builder do Laravel (`DB::table(...)`) ou PDO direto dentro dos repositórios — **nunca**
   Eloquent como entidade de domínio, e nunca um Model do Eloquent atravessando a fronteira de volta
   para `Domain/`. O teste arquitetural de dependência (`tests/Architecture/DependencyRuleTest.php`,
   já previsto na Fase 0) passa a barrar também `Illuminate\*` dentro de `Domain/`/`Application/`.
2. **Atualização deliberada e testada, nunca automática.** A versão fica travada em `composer.lock`
   (padrão do PHP — nada se atualiza sozinho por padrão). A prática adotada é: revisão da versão do
   Laravel **uma vez por ano, ou antes disso se sair um aviso de segurança relevante**, com a suíte de
   testes completa rodando verde antes de qualquer atualização ir para produção. Nunca "deixar rodando
   pra sempre sem tocar" — isso deixaria falhas de segurança conhecidas sem correção num sistema com
   toda a vida financeira do usuário (risco R07).

**Dependências:** `laravel/laravel` (framework), `laravel/sanctum` (se necessário para tokens de API
futuros), `robthree/twofactorauth` (TOTP — o pacote nativo do Laravel para 2FA é pago/Fortify+Jetstream
mais pesado do que precisamos), `smalot/pdfparser` (V1, extração de PDF) — restante do stack usa o que
o Laravel já traz (Monolog, PHPMailer via `Mail`, migrações via `php artisan migrate` no lugar de Phinx).

**Consequências:** MVP começa mais rápido (login, sessão, validação, e-mail já vêm prontos — a semana
que seria gasta nisso vai para features). Em troca, a Fase 0 inclui uma tarefa extra e não-negociável:
configurar o teste de regra de dependência **antes** de escrever a primeira linha de `Domain/`, para
que a conveniência do Eloquent nunca vaze pra dentro da regra de negócio. Revisão anual de versão vira
item permanente do checklist de manutenção (`docs/11-seguranca.md`).

**Aprovado pelo PO em 2026-08-04**, com a condição de atualização deliberada (nunca automática) —
condição que já é reforçada pela prática padrão do Composer e formalizada acima como política do projeto.

---

## ADR-0006 — Dinheiro sempre em centavos inteiros 🔵

`BIGINT` de centavos e um Value Object `Money`. **Nunca** FLOAT/DOUBLE (`0.1 + 0.2 != 0.3`), nunca
DECIMAL (correto, mas convida a aritmética em PHP com float na volta). `Money::allocate()` faz rateio
de parcelas distribuindo o resto de centavos — sem isso, 12× de R$ 100,00 vira R$ 1.199,99.
`Money` **proíbe** construção a partir de float. Suporta até ~92 quatrilhões de centavos.

---

## ADR-0007 — Dois eixos temporais; cartão agrega em evento único de fatura 🔵

Toda transação tem **competência** (`occurred_on` — quando o fato aconteceu) e **caixa**
(`cash_effect_on` — quando o dinheiro se move). Para compra no cartão, `cash_effect_on` é o
vencimento da fatura.

**Regra crítica (invariante I4):** o fluxo de caixa **não** soma transações de cartão individualmente.
Soma um único evento por fatura, na conta pagadora, composto de parcelas comprometidas + recorrentes
no cartão + estimativa de gasto variável. Quando o pagamento real da fatura entra no ledger, ele
**substitui** a projeção.

Sem essa regra explícita e testada, o sistema conta cartão duas vezes — o erro mais comum e mais
destrutivo em app financeiro, porque a tela que você mais confia passa a mentir.

---

## ADR-0008 — Multi-tenant desde o dia 1, UX single-user 🔵

`user_id` em toda tabela de dados, primeira coluna de todo índice composto, filtro obrigatório em
todo repositório. Custo hoje: ~zero. Sem isso, virar familiar em V3 seria migração de 40 tabelas.
UX permanece single-user (sem seletor de conta, sem convites) até V3.

---

## ADR-0009 — Autenticação: sessão server-side + Argon2id + TOTP, sem cadastro público 🔵

Detalhes em `docs/11-seguranca.md#1`. Resumo do porquê: JWT não traz nada aqui (o cliente é o próprio
site) e traz impossibilidade de revogação. Sessão em tabela permite revogar, listar dispositivos e
expirar de verdade. Cadastro público não existe → um vetor de ataque inteiro eliminado. Para
integrações futuras (WhatsApp/API), **API tokens em tabela própria** com escopo e revogação individual.

---

## ADR-0010 — Recorrência é agrupador, nunca gerador 🔵 (requisito do PO)

Marcar como recorrente **não** cria lançamentos. `recurrence_occurrences` guarda *expectativas*, que
alimentam apenas a projeção — jamais aparecem como movimentação. Invariante **I5** testado.

Geradores de lançamento são a causa nº 1 de lixo em apps financeiros: geram 12× "Internet", o CSV do
banco traz o pagamento real, e você fica com 24 registros e um saldo errado. O PO acertou ao proibir.

---

## ADR-0011 — Frontend: SPA leve em ES modules 🔵

| Opção | Prós | Contras |
|-------|------|---------|
| **ES modules + store mínimo + Chart.js, buildado com esbuild** ✅ | ~120 KB total; zero framework para envelhecer; API-first já pronta para WhatsApp/mobile; sem Node em produção | escrevemos ~300 linhas de router/store |
| Server-rendered PHP + JS pontual | simples, SEO | UX pior (recarga a cada ação); duplicaria lógica de apresentação quando a API existir para outros canais |
| React/Vue | ecossistema, componentes | build complexo, 150+ KB, breaking changes a cada major, exagero para este escopo |
| Alpine.js | leve, declarativo | limitado para listas grandes e gráficos interativos |

**Decisão:** ES modules + esbuild + Tailwind CLI. **Assets compilados são versionados no Git** (o host
não tem Node). Sem CDN — a CSP proíbe host externo.

---

## ADR-0012 — Cascata de categorização barata→caro 🔵

Cinco níveis: regra do usuário → memória de merchant → dicionário semente → Naive Bayes → LLM.
Detalhes em `docs/03-fluxos.md#5`. Efeito: **o custo de IA decresce com o uso** e o sistema funciona
mesmo sem IA. Limiar de confiança 0,70 para gravação automática; abaixo disso vai para revisão (P4).

---

## ADR-0013 — Soft delete + audit log imutável 🔵

`deleted_at` em tudo que o usuário apaga; `audit_log` append-only com antes/depois em JSON. Nunca
perder um dado financeiro por clique errado, e sempre poder responder "por que este valor está assim?".
Custo: filtro `deleted_at IS NULL` em toda query (encapsulado no repositório) e uma tabela que cresce
devagar. Barato demais para não fazer.

---

## ADR-0014 — Importadores como plugins com perfil em banco 🔵

Duas camadas: `Importer` (interface, código) para formatos e bancos conhecidos; `importer_profiles`
(banco) para layouts que o **próprio usuário** mapeia na UI. É a segunda camada que garante
longevidade: banco novo em 2029 não precisa de deploy, precisa de 2 minutos de mapeamento.

---

## ADR-0015 — Anexos fora do webroot, servidos por PHP 🔵

`storage/uploads/yyyy/mm/<ulid>.<ext>` fora do webroot. Acesso só por `GET /documents/{id}/download`
com autenticação + autorização por `user_id`, `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`.
Custo: PHP no caminho do download (irrelevante nesse volume). Ganho: um comprovante de PIX nunca
vira URL pública indexável.

---

## ADR-0016 — Read models materializados para dashboards 🔵

`cashflow_snapshots`, `account_daily_balances`, `category_month_rollup`, reconstruídos por cron com
invalidação por evento (`snapshot_dirty`). Alternativa (agregar ao vivo) daria 2–4 s em host
compartilhado com anos de histórico — e a tela principal do produto é justamente um dashboard.
Custo: possível defasagem de minutos, resolvida por invalidação e rebuild imediato do período afetado.

---

## ADR-0017 — WhatsApp como canal de ingestão, não módulo novo ⚪ V3

Arquitetura completa em `docs/12-crescimento.md#2`. Implementa `IngestionChannel`; extração,
classificação, conciliação e projeção são **reuso total**. ~2 semanas para o canal completo.
Provedor: WhatsApp Cloud API direto. Segurança: HMAC + whitelist de números + idempotência por `wamid`.

---

## Pendências de aprovação

| ADR | Decisão | Impacto se mudar depois |
|-----|---------|------------------------|
| 0002 | Fila via cron | médio — trocar 1 classe + infra |
| 0003 | Privacidade / provedor de IA | baixo tecnicamente, **alto pessoalmente** |
| 0004 | Modelo de parcelas | **alto** — migração de dados |
| 0005 | Framework | **alto** — reescrita da camada de infra |
