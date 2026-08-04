# ADR-0019 — Validação de integridade: cadeia de saldo e conferência cruzada

- **Status:** 🔵 aceita
- **Data:** 2026-08-04
- **Corrige:** a premissa de `docs/06-ingestao-ia.md#21` de que sempre haveria total de fatura para conferir

## Contexto

O desenho original definiu a **validação cruzada obrigatória** `soma(itens) == total da fatura` como
o mecanismo que impede gravar dados errados silenciosamente (risco R01, o mais grave do projeto).

Os arquivos reais do XP mostraram que essa premissa não se sustenta em todo canal:

- **Fatura CSV do XP: não tem linha de total, nem mínimo, nem vencimento, nem limite.** Não há o que
  conferir. E quando a fatura está aberta (ADR-0018), um total nem existiria conceitualmente.
- **Extrato CSV do XP: tem coluna `Saldo` com o saldo corrente após cada lançamento** — uma
  informação que o desenho original não previa e que é uma validação *mais forte*.

Sem uma alternativa, o canal mais importante do MVP ficaria sem rede de proteção.

## Decisão

Validação de integridade passa a ser uma **estratégia por perfil de importador**, com três mecanismos
em ordem de força:

### 1. Cadeia de saldo (mais forte) — quando `has_running_balance`

```
Em ordem decrescente:  saldo[i] == saldo[i+1] + valor[i]   ∀i
```

Prova que nenhuma linha foi perdida, duplicada, mal-parseada ou teve sinal invertido. Verificado nos
dados reais: **0 quebras em 109 verificações**.

- Quebra na cadeia → **bloqueia o commit** e mostra exatamente qual linha rompeu.
- Bônus: o saldo da última linha é a fonte da verdade para `accounts` — o saldo do sistema passa a ser
  conferido contra o banco a cada importação, não calculado às cegas.

### 2. Soma × total declarado — quando o documento traz total (PDF de fatura, fatura fechada)

O mecanismo original, mantido onde existe.

### 3. Conferência cruzada entre canais (para fatura sem total) — ⭐ nova

Quando a fatura fecha, seu total tem de bater com o pagamento correspondente no extrato do mês
seguinte (`PAGAMENTO DE FATURA` / `Pagamento para BANCO XP S.A`, pareados por `transfer_group_id`).

```
total_da_fatura(mês N)  ==  pagamento_no_extrato(mês N+1)   (± tolerância de pagamento parcial)
```

Divergência gera item na fila de Revisão, não bloqueio — porque pagamento parcial e parcelamento de
fatura são legítimos. Este mecanismo **só é possível porque as duas fontes vivem no mesmo sistema**, e
é o melhor argumento a favor da conciliação multicanal: duas fontes independentes se auditam.

### Regra geral

**Nenhum perfil de importador pode ter zero mecanismo de validação.** Perfil novo criado pelo usuário
sem `has_running_balance` e sem total declarado entra em modo `manual_review_required`: o preview exige
conferência explícita item a item na primeira importação daquele perfil.

## Consequências

- `importer_profiles.options` ganha `has_running_balance` e `validation_strategy`.
- `import_batches.validation` passa a registrar qual estratégia rodou e o resultado.
- Invariante nova: **I13 — importação com `has_running_balance` e cadeia rompida nunca é commitada.**
- O achado dos R$ 3.804,19 em pagamentos aparentemente duplicados (`docs/13-perfis-importadores-xp.md#13`)
  foi encontrado **por** essa validação — a cadeia fechar significa que ambos os débitos são reais no
  extrato, o que transforma uma suspeita vaga em fato verificável.
