# ADR-0022 — Antecipação de fatura não é duplicidade

- **Status:** 🔵 aceita (confirmado pelo PO em 2026-08-04)
- **Complementa:** ADR-0019 (validação por cadeia de saldo) e ADR-0007 (cartão agrega em evento único)

## Contexto

Os dados reais mostraram dois pares de pagamentos com destino ao cartão (`PAGAMENTO DE FATURA` /
`Pagamento para BANCO XP S.A`), mesmo valor, poucos dias de diferença, dentro do mesmo mês:

```
29/07/26  PAGAMENTO DE FATURA          -R$ 2.710,53
03/08/26  Pagamento para BANCO XP S.A  -R$ 2.710,53
```

O PO confirmou: **quando há mais de um pagamento de fatura no mesmo ciclo, é antecipação** — ele
paga a fatura corrente e adianta parte ou todo o valor da fatura seguinte, antes dela fechar. Não é
erro do banco nem duplicidade de lançamento.

Sem tratamento explícito, dois problemas surgiriam:
1. O `Reconciler` (scoring por similaridade, `docs/03-fluxos.md#4`) veria "mesmo valor, poucos dias,
   mesma conta, descrições parecidas" e classificaria como possível duplicata (score alto) —
   perguntando ao usuário todo mês por algo que já é um hábito seu.
2. A **projeção de fluxo de caixa** (ADR-0007) continuaria prevendo o pagamento integral da próxima
   fatura na data de vencimento, ignorando que parte dela já foi paga antecipadamente — superestimando
   a saída futura.

## Decisão

1. **Reconciliação:** pagamentos com destino a `credit_cards.payment_account_id` correspondente
   (via merchant/descrição casando com o emissor do cartão) **nunca** entram no scoring de
   duplicidade de transação comum. São tratados como uma classe própria: `payment_method` já os
   identifica (`PAGAMENTO DE FATURA` / nome do emissor), e múltiplas ocorrências no mesmo mês são
   **esperadas**, não suspeitas.

2. **Modelagem:** cada pagamento de fatura é uma `transaction` normal (`direction=out`,
   `payment_method` apropriado), com `transfer_group_id` apontando para o cartão. Quando a soma dos
   pagamentos de um ciclo excede o total já fechado da fatura corrente, o excedente é reconhecido como
   **crédito antecipado** contra a próxima fatura:

   ```
   antecipado_cents = SUM(pagamentos do ciclo) - card_statements.total_cents (fatura corrente, quando fechada)
   ```

   Se a fatura corrente ainda está aberta (ADR-0018) — como no caso real, onde a 2ª fatura fecha
   depois do 2º pagamento — o excedente fica provisoriamente marcado como antecipação **pendente de
   confirmação no fechamento**, sem certeza absoluta do valor até a fatura fechar.

3. **Efeito na projeção (`CashFlowEngine`, ADR-0007):** o evento de saída projetado para o
   vencimento da próxima fatura é **reduzido pelo valor já antecipado**:

   ```
   projeção_do_vencimento = committed_cents_do_mês - antecipado_cents
   ```

   Sem isso, o sistema mostraria uma saída maior do que a que realmente vai ocorrer — o oposto do
   objetivo do produto (ver o futuro corretamente).

4. **UX:** a tela de Cartões (`docs/08-ux.md#44`) ganha uma linha "Já antecipado para a próxima
   fatura: R$ X" quando aplicável, para que o hábito do usuário fique visível, não escondido.

## Consequências

- `card_statements` ganha coluna `advance_payment_cents` (soma de antecipações reconhecidas para o
  mês, nullable, calculada no rebuild da projeção).
- Nova regra determinística no `Reconciler`: pagamentos de fatura são excluídos do fingerprint de
  dedup comum e avaliados por uma regra própria (identidade do destino + janela do ciclo), não por
  scoring de similaridade genérico.
- Sem migração de dados: é lógica de domínio nova sobre uma coluna adicional, não mudança de modelo
  existente.
