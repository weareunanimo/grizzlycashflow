# ADR-0018 — Fatura aberta e importação incremental

- **Status:** 🔵 aceita (decorre de requisito explícito do PO + evidência nos dados reais)
- **Data:** 2026-08-04
- **Substitui/complementa:** ADR-0004

## Contexto

O PO declarou desde o início: *"toda semana poderei enviar uma nova fatura"*. Os dados reais
confirmaram o que isso significa na prática: o arquivo `Fatura20260901.csv`, exportado em 04/08/2026,
é uma **fatura ainda aberta** — contém compras de 01/08 e 02/08, não tem total, não tem vencimento e
receberá mais lançamentos até o fechamento (~dia 17–18).

O desenho original de ADR-0004 tratava a fatura como documento fechado, importado uma vez. Isso está
incompleto para o fluxo real de uso.

## Opções

| Opção | Prós | Contras |
|-------|------|---------|
| **A) Statement como container mutável enquanto `status='open'`; reimportação é reconciliação incremental** ✅ | reflete a realidade; `UNIQUE(credit_card_id, reference_month)` já existe; reimportar semanalmente vira operação natural; o comprometido futuro fica sempre atualizado | exige distinguir "item novo" de "item já visto" em cada reimportação — mas isso é exatamente o que `row_hash` e `group_key` fazem |
| B) Um `card_statement` novo por arquivo importado | simples de gravar | quebra o `UNIQUE(cartão, mês)`; 4 importações do mesmo mês = 4 faturas fantasma e comprometimento contado 4× |
| C) Só aceitar fatura fechada | validação por total funcionaria | o PO perderia visibilidade da fatura em formação — justamente o dado mais útil para decidir uma compra hoje |
| D) Apagar e recriar os itens do mês a cada reimportação | simples | destrói categorizações e correções manuais do usuário; viola o princípio "dado humano > dado de máquina" |

## Decisão

**Opção A.** Regras:

1. `card_statements.status` distingue `open` (em formação) de `closed`/`paid`. Um mês só é fechado
   quando o arquivo traz total/vencimento, ou quando o usuário marca, ou quando um pagamento
   correspondente é conciliado no extrato.
2. Reimportar o mesmo mês **atualiza** o statement existente. Cada linha passa pelo caminho normal:
   `row_hash` já visto → `skip`; compra parcelada com `group_key` conhecido → apenas confirma a parcela;
   linha nova → `create`.
3. **Categorizações e correções manuais nunca são sobrescritas** por reimportação (precedência
   `user > pdf > csv > ocr > text`, já definida em `docs/07-inteligencia.md#14`).
4. Item que **desaparece** entre duas importações do mesmo mês abertos (estorno pré-fechamento) é
   marcado `needs_review` com motivo `vanished_on_reimport` — nunca apagado silenciosamente.
5. O preview mostra explicitamente o delta: *"12 novos · 98 já importados · 0 desaparecidos"*.

## Consequências

- Uma coluna nova em `import_rows`: nada. `row_hash` e `proposed_decision` já cobrem.
- `card_statements` ganha semântica de estado mais rica — já previsto no enum.
- Invariante nova, testável: **I11 — reimportar o mesmo arquivo N vezes produz o mesmo estado final**
  (idempotência total do lote), e **I12 — reimportar uma fatura aberta com itens adicionais nunca
  altera categoria definida pelo usuário**.
- Efeito colateral positivo: o "comprometido futuro" fica correto **durante** o mês, não só depois do
  fechamento.
