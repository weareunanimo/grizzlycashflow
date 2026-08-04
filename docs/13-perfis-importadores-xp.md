# 13 — Perfis de Importador: Banco XP e Cartão Visa Black XP

Este documento foi derivado de **dois arquivos reais** fornecidos pelo PO:
`extrato_de_05062026_ate_04082026.csv` (110 lançamentos) e `Fatura20260901.csv` (110 itens).
Ambos foram pseudonimizados (`tests/Fixtures/anonymize.php`) e versionados como fixtures de regressão
em `tests/Fixtures/csv/` — todos os valores, datas e totais preservados; nomes de pessoas e documentos
substituídos. Ver `tests/Fixtures/csv/README.md`.

**Detalhe de parsing encontrado:** a fatura vem com **BOM UTF-8** antes do cabeçalho (`\xEF\xBB\xBF Data`).
Sem strip do BOM, a primeira coluna é lida como `﻿Data` e o mapeamento de colunas por nome falha
silenciosamente. Já previsto em `docs/06-ingestao-ia.md#3`, agora confirmado em dado real.

---

## 1. Perfil `xp_conta_csv` — extrato da conta

```
Data;Hora;Descricao;Valor;Saldo
04/08/26;10:19:13;Rendimento automático;R$ 0,04;R$ 1.979,96
03/08/26;08:08:55;Pagamento para BANCO XP S.A;-R$ 2.710,53;R$ 1.979,63
```

```jsonc
{
  "key_slug": "xp_conta_csv",
  "format": "csv",
  "options": {
    "delimiter": ";", "encoding": "UTF-8", "skip_rows": 0, "has_header": true,
    "date_format": "d/m/y",                  // ⚠️ ANO DE 2 DÍGITOS
    "decimal_sep": ",", "thousands_sep": ".",
    "amount_mode": "signed",                 // sinal no próprio valor, prefixo "R$ "/"-R$ "
    "amount_strip": ["R$", " "],
    "has_running_balance": true              // ⭐ coluna Saldo — usada para validação
  },
  "column_map": { "date": 0, "time": 1, "description": 2, "amount": 3, "balance": 4 },
  "detection": { "header_contains": ["Data","Hora","Descricao","Valor","Saldo"] }
}
```

### 1.1 ⭐ A coluna `Saldo` substitui a validação de total

O extrato traz saldo corrente após cada lançamento. Em ordem decrescente (como o XP exporta):

```
saldo[i] == saldo[i+1] + valor[i]     para todo i
```

**Verificado nos dados reais: 0 quebras em 109 verificações.** Isso é uma validação de integridade
*mais forte* que a soma × total de uma fatura: prova que nenhuma linha foi perdida, duplicada ou
mal-parseada. Passa a ser obrigatória para qualquer perfil com `has_running_balance` — e o saldo
final alimenta `accounts.opening_balance` na primeira importação.

### 1.2 Padrões de descrição (extraídos dos dados reais)

| Padrão | Ocorrências | Regra de normalização |
|--------|------------:|----------------------|
| `Rendimento automático` (e `Rendimento automático do dia dd/mm/yyyy`) | **42 (38%)** | categoria Rendimentos · ver ADR-0020 |
| `Pix enviado para <NOME>` | 35 | direção `out`, método `pix`, → `counterparty` |
| `Pagamento para <NOME>` | 17 | direção `out`, método `boleto`/`direct_debit`, → `counterparty` |
| `Pix recebido de <NOME>` | 10 | direção `in`, método `pix`, → `counterparty` |
| `PAGAMENTO DE FATURA` | 4 | ⭐ pareia com `credit_cards` → `transfer_group_id` |
| `Salário recebido` | 1 | direção `in`, categoria Salário, recorrência mensal |
| `TED recebida de <NOME>` | 1 | direção `in`, método `ted` |

O nome do favorecido pode vir com CPF/CNPJ colado: `Pix enviado para 49 874 851 Daniel Felipe Souza`,
`49.077.350 Vitor Mateus Teixeira Alves`, `Raul Ribeiro da Silva Marques 07842844909`. O
`CounterpartyNormalizer` extrai o documento (guarda apenas máscara + hash, conforme ADR de privacidade)
e limpa o nome. Sufixos `S.A`, `LTDA`, `ME`, `EIRELI` e `Em Recuperacao Judicial` são removidos da chave.

### 1.3 ✅ Confirmado pelo PO: pagamentos duplos = antecipação de fatura

Dois pares de pagamentos com o mesmo valor aparecem no extrato:

| Data | Descrição | Valor |
|------|-----------|-------|
| 29/07/26 21:33 | `PAGAMENTO DE FATURA` | −R$ 2.710,53 |
| 03/08/26 08:08 | `Pagamento para BANCO XP S.A` | −R$ 2.710,53 |
| 01/07/26 08:51 | `PAGAMENTO DE FATURA` | −R$ 1.093,66 |
| 01/07/26 08:09 | `Pagamento para BANCO XP S.A` | −R$ 1.093,66 |

**Confirmado pelo PO: quando há mais de um pagamento da fatura no período, é antecipação — o
usuário paga a fatura corrente e adianta parte/todo o valor projetado da fatura seguinte, antes
mesmo dela fechar.** Não é duplicidade nem erro do banco.

**Consequência para o motor de conciliação:** este padrão (2 débitos de valor igual ou distinto,
mesmo destino `BANCO XP S.A`/`PAGAMENTO DE FATURA`, dentro da janela do mesmo mês de fatura) **não**
deve ser tratado como par de duplicatas. O `Reconciler` precisa reconhecer explicitamente
"segundo pagamento de fatura no mesmo ciclo" como **antecipação legítima**, não como candidato a
merge — ver ADR-0022.

---

## 2. Perfil `xp_cartao_fatura_csv` — fatura do Visa Black

```
Data;Estabelecimento;Portador;Valor;Parcela
06/02/2026;ELECTROLUX ELECT;FELIPPE DE PIN;R$ 203,84;7 de 10
01/08/2026;SUPERLEGAL COMERCIO DE;FELIPPE DE PIN;R$ 129,99;-
```

```jsonc
{
  "key_slug": "xp_cartao_fatura_csv",
  "format": "csv",
  "options": {
    "delimiter": ";", "encoding": "UTF-8", "has_header": true,
    "date_format": "d/m/Y",                  // ⚠️ 4 dígitos aqui (difere do extrato!)
    "amount_mode": "signed", "amount_strip": ["R$", " "],
    "date_is_purchase_date": true,           // ⭐ é a data da COMPRA, não do lançamento
    "installment_column": 4,
    "installment_pattern": "^\\s*(\\d{1,2})\\s+de\\s+(\\d{1,2})\\s*$",
    "no_total_row": true,                    // ⚠️ não há total, mínimo, vencimento nem limite
    "may_be_open_statement": true            // ⭐ ver ADR-0018
  },
  "column_map": { "purchase_date": 0, "description": 1, "holder": 2, "amount": 3, "installment": 4 },
  "detection": { "header_contains": ["Data","Estabelecimento","Portador","Valor","Parcela"] }
}
```

### 2.1 ⭐ `Data` é a data da compra — e isso melhora o `group_key`

Confirmado nos dados: `06/02/2026 … 7 de 10` numa fatura de 09/2026 (compra em fevereiro, 7ª parcela
em setembro). Como a data da compra é **idêntica em todas as faturas** em que a compra aparece, ela é
uma âncora mais direta que o mês derivado:

```
group_key = sha256( credit_card_id | merchant_key | installment_amount_cents
                    | installments_total | purchase_date )
```

Fallback (PDF ou layout sem data de compra): `first_reference_month` derivado
(`reference_month − (n−1) meses`), como projetado originalmente. Os dois são estáveis entre faturas.

**⚠️ Correção importante ao desenho original:** eu supunha que `mês da compra + 1 = primeiro mês de
cobrança`. **Isso é falso em 23 dos 72 itens parcelados (32%)** — compras próximas ao fechamento
rolam para o ciclo seguinte (lag de 2 meses). Derivar o primeiro mês a partir da data da compra
produziria `group_key` errado em um terço dos casos. Manter a derivação a partir de
`reference_month − (n−1)` **ou** usar `purchase_date` direto; **nunca** somar 1 mês à compra.

### 2.2 Ciclo do cartão inferido dos dados

| Parâmetro | Valor inferido | Evidência |
|-----------|---------------|-----------|
| Fechamento | ~dia 17–18 | compra de 14/07 tem 1ª parcela em ago; compra de 23/07 tem 1ª parcela em set |
| Vencimento | dia 01 do mês seguinte ao fechamento | nome do arquivo `Fatura20260901` |
| Estado deste arquivo | **ABERTA** | contém compras de 01–02/08 e o export é de 04/08 |

A confirmar com você — o app do XP mostra fechamento e vencimento exatos.

### 2.3 Coluna `Parcela` — formatos reais encontrados

| Valor | Significado | Tratamento |
|-------|-------------|-----------|
| `4 de 7` | parcela 4 de 7 | `n=4, N=7` |
| `-` | compra à vista | `n=1, N=1` |
| `de 1` | ⚠️ **número ausente** — só no lançamento `Pagamento de fatura` | tratar como crédito de pagamento, não como compra |
| `1 de 2`, `1 de 12` | primeira parcela | cria a compra e projeta N−1 futuras |

`YELUMSEG PARC8` tem "PARC8" no **nome do estabelecimento** com `Parcela = -`. O regex exige `n` e `N`,
então não casa — mas é a prova de que detectar parcela pela descrição precisa da validação de
plausibilidade já prevista.

### 2.4 Valores negativos = estornos e pagamentos

4 dos 110 itens são negativos: `IFD*BR −79,80`, `TEMU.COM −132,67`, `LATAM AIR*GNGWZC −113,42`,
`Pagamento de fatura −2.710,53`. Regras:
- estorno de compra → procura a compra original por merchant+valor; se parcelada, marca
  `purchase.status = refunded` e cancela parcelas futuras;
- `Pagamento de fatura` → **não é gasto**: pareia com o lançamento do extrato via `transfer_group_id`
  e é excluído de análises de categoria.

### 2.5 Ruído de gateway — lista real para o `MerchantNormalizer`

Prefixos encontrados, com contagem: `MP` (17), `SHOPEE` (9), `IFD` (9), `MERCADOLIVRE` (8),
`AMAZONMKTPLC` (2), `DL` (2), `GNT` (2), `ZP` (2), `NUV` (2), `VINDI` (2), `PG` (2), `ANTHROPIC` (2),
`DM` (2), `ENJOEI`, `BPG`, `MLP`, `BR1`, `MADEIRA`, `PGZ`, `REN`, `HTM`.

Variações que **têm** de colapsar para a mesma chave:
`MP*MERCADOLIVRE` · `MP *MERCADOLIVRE` · `MERCADOLIVRE*MERCADOLIVRE` · `DM          *TEMU` ·
`GNT*TEMU` · `TEMU.COM` · `HTM    *MEUASSESSOR` · `BR1    *COLZANI MOVEIS` · `VINDI  *COALA`.

Regra: `^[A-Z0-9]{2,14}\s*\*\s*` removido; espaços colapsados; sufixo de cidade/UF removido
(`ANGELONI ELETRO 51`, `CEA MODAS 0304`, `CONSTRUCOLOR 005 VELHA` → remover código numérico final).

### 2.6 ⭐ Colisões que validam o `group_key`

Dois casos reais de **mesmo estabelecimento, mesma data, mesmo `n/N`, valores diferentes**:

```
05/07/2026  MP *DIGITALIMPORT   2 de 10   R$   5,58   +   R$ 189,90
09/07/2026  MP*MERCADOLIVRE     2 de 10   R$  64,96   +   R$  56,33
```

São **compras distintas**. Como `installment_amount_cents` faz parte do `group_key`, elas resolvem
para compras separadas corretamente. Se o `group_key` fosse só merchant+data+N, o sistema fundiria
duas compras e perderia R$ 195,48 de comprometimento futuro. Estes dois casos entram na suíte de
testes como regressão permanente.

---

## 3. O que os dados reais já revelam sobre as suas finanças

Calculado direto dos arquivos, sem sistema nenhum — é a prova de que o modelo funciona:

| Métrica | Valor |
|---------|-------|
| Itens na fatura de 09/2026 (aberta) | 110 |
| Compras/débitos | R$ 12.268,56 |
| Estornos e pagamentos | −R$ 3.036,42 |
| Parcial da fatura | **R$ 9.232,14** |
| Itens parcelados | 72 (R$ 7.206,64 nesta fatura) |
| Itens à vista | 38 (R$ 2.025,50) |
| **⭐ Comprometido em parcelas FUTURAS (após 09/2026)** | **R$ 23.413,96** |

Distribuição do comprometido futuro — este é o gráfico que motivou o projeto:

| Mês | Comprometido | |
|-----|-------------:|---|
| 2026-10 | R$ 5.831,82 | ████████████████████ |
| 2026-11 | R$ 4.534,94 | ███████████████ |
| 2026-12 | R$ 3.846,07 | █████████████ |
| 2027-01 | R$ 2.865,86 | ██████████ |
| 2027-02 | R$ 2.165,73 | ███████ |
| 2027-03 | R$ 1.889,04 | ██████ |
| 2027-04 | R$ 1.655,22 | █████ |
| 2027-05 | R$ 475,44 | █ |
| 2027-06 | R$ 107,98 | |
| 2027-07 | R$ 20,93 | |
| 2027-08 | R$ 20,93 | |

Só de parcelas já contratadas — antes de qualquer compra nova, de recorrentes e do financiamento
Volkswagen (R$ 2.284,43/mês). **Nenhum PDF foi lido para chegar aqui: o CSV da fatura basta**, o que
antecipa boa parte do valor do MVP.

---

## 4. Recorrências detectadas nos 2 meses de extrato

Rodando o detector projetado (agrupamento por favorecido, intervalo regular, valor estável):

| Favorecido | Ocorr. | Intervalo | Valor | Veredito |
|-----------|-------:|-----------|-------|----------|
| Veronica Lima do Vale | 8 | **7,7,7,7,7,7,7 dias** | R$ 260,00 fixo | ✅ **SEMANAL** — caso perfeito |
| Banco Volkswagen | 2 | 30 dias | R$ 2.284,43 fixo | ✅ MENSAL — financiamento |
| Residencial Recanto das Araras | 2 | 30 dias | 475,01 → 540,98 | ✅ MENSAL + **reajuste de 13,9%** |
| Ministério da Fazenda | 2 | 37 dias | 209,38 → 211,70 | ✅ MENSAL (variação 1,1%) |
| Celesc | 2 | 33 dias | 429,84 → 88,27 | ⚠️ MENSAL, variação −79% → `needs_review` |
| Escola Riacho Doce | 7 | 0,0,10,20,0,0 | R$ 50 – 1.419 | ❌ **falso positivo** |
| Julia Heck Junge | 4 | 0,26,26 | R$ 100 – 4.500 | ❌ falso positivo |

**Correção necessária ao detector:** agrupar por favorecido **não basta**. A Escola Riacho Doce emite
**três boletos no mesmo dia** com valores distintos (R$ 236,73 + R$ 745,00 + R$ 1.419,00, repetidos
em 10/06 e 10/07) — são **três recorrências mensais separadas**, não uma irregular. O detector precisa
agrupar por `(favorecido, cluster de valor ±15%)` antes de avaliar a regularidade do intervalo. Sem
isso, ele erra justamente nos casos de mensalidade escolar — comuns e importantes.

Registrado em `docs/07-inteligencia.md` e no ADR-0021.

---

## 5. Consequências para o roadmap

| Antes | Depois dos dados reais |
|-------|----------------------|
| MVP começava por parser de PDF | **MVP começa pelo CSV da fatura XP** — mais simples, mais confiável e já entrega o objetivo nº 1 |
| Validação por soma × total da fatura | Fatura XP não tem total → validação por cadeia de saldo (extrato) e por conferência com o pagamento do mês seguinte |
| Fatura tratada como documento fechado | Precisa suportar **fatura aberta reimportada semanalmente** (ADR-0018) — que é exatamente o seu fluxo declarado |
| Parser de PDF na semana 2 do MVP | Movido para V1: os CSVs cobrem o caso de uso; PDF só se você não tiver CSV de algum período |
