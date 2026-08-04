# Fixtures de CSV — arquivos reais

Arquivos reais do PO, usados como **fixtures de regressão permanente**. Nenhum parser é considerado
pronto sem passar sobre eles.

| Arquivo | Origem | O que valida |
|---------|--------|--------------|
| `xp_conta_2026-06-05_a_2026-08-04.csv` | extrato Banco XP, 110 lançamentos | cadeia de saldo (0 quebras em 109 verificações), data `d/m/y`, padrões `Pix enviado/recebido`, `Pagamento para`, 42 linhas de `Rendimento automático`, pagamentos de fatura aparentemente duplicados |
| `xp_visa_black_fatura_2026-09_aberta.csv` | fatura Visa Black XP, 110 itens, **aberta** | parcela `N de M`, parcela `de 1` (malformada), 4 valores negativos, data `d/m/Y` = data da compra, lag inconsistente de 1–2 meses, 21 prefixos de gateway, 2 colisões de `group_key`, comprometido futuro de R$ 23.413,96 |

## ✅ Já pseudonimizados

Rodados por `php tests/Fixtures/anonymize.php <arquivo> --in-place` **antes** do primeiro commit:

- **nomes de pessoas físicas** (inclusive vendedores MEI no campo Estabelecimento) → pseudônimos
  estáveis, mesmo comprimento aproximado;
- **CPF/CNPJ embutidos** na descrição → mascarados com `*`, preservando o formato
  (`49.077.350 Vitor…` → `**.***.*** Victor…`), para que os testes de extração de documento
  continuem exercitando o mesmo caminho de código.

**Preservado intacto** (é o objeto dos testes): valores, datas, horas, saldos, parcelas, razão social
de empresas e órgãos (CELESC, ESCOLA RIACHO DOCE, BANCO VOLKSWAGEN, MUNICIPIO DE BLUMENAU) e as marcas
do campo Estabelecimento.

Conferido: **todos os totais e contagens abaixo são idênticos antes e depois da pseudonimização.**

## Números de referência (asserções esperadas)

**Extrato:** 110 lançamentos · 0 quebras na cadeia de saldo · 42 rendimentos somando R$ 18,63 ·
35 PIX enviados (−R$ 15.581,20) · 10 PIX recebidos (R$ 10.846,91) · 17 pagamentos (−R$ 14.997,30) ·
saldo final R$ 1.979,96

**Fatura:** 110 itens · parcial R$ 9.232,14 · 106 débitos R$ 12.268,56 · 4 créditos −R$ 3.036,42 ·
72 parcelados · 38 à vista · **comprometido futuro R$ 23.413,96** distribuído em 11 meses
(pico R$ 5.831,82 em 2026-10)

**Recorrências que o detector precisa achar:** Veronica Lima do Vale R$ 260 semanal (8 ocorrências,
gaps de exatamente 7 dias) · Banco Volkswagen R$ 2.284,43 mensal dia 15 · Recanto das Araras mensal
com reajuste de 13,9% · Escola Riacho Doce **três** recorrências mensais no mesmo dia (236,73 / 745,00
/ 1.419,00) — este é o caso que quebra o detector ingênuo (ADR-0021)
