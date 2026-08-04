# ADR-0021 — Detecção de recorrência agrupa por favorecido **e** cluster de valor

- **Status:** 🔵 aceita (correção de desenho comprovada por dados reais)
- **Data:** 2026-08-04
- **Corrige:** `docs/03-fluxos.md#6` e `docs/07-inteligencia.md#26`

## Contexto

O `PatternDetector` foi especificado como: *"3+ transações, mesmo merchant/counterparty, intervalo
regular (±5 dias), valor estável (±15%) → sugerir recorrência"*.

Rodando esse algoritmo sobre o extrato real de 2 meses, ele acerta os casos fáceis e **erra
sistematicamente o caso mais importante**:

```
ESCOLA RIACHO DOCE    7 ocorrências   gaps = [0, 0, 10, 20, 0, 0]   R$ 50,00 – 1.419,00
                      → intervalo irregular, variação de 2.738%  → DESCARTADO
```

A realidade por trás desses números: a escola emite **três boletos no mesmo dia**, todo mês —
R$ 236,73, R$ 745,00 e R$ 1.419,00 — pagos em 10/06 e 10/07, mais um PIX avulso de R$ 50,00 em 20/06.

São **três recorrências mensais de valor fixo** + um pagamento pontual. Agrupando só por favorecido, os
gaps viram `[0,0,...]` (mesmo dia) e a variação de valor explode — e o detector descarta exatamente as
mensalidades escolares, que são gasto fixo alto e recorrente por anos.

O mesmo padrão aparece em `Julia Heck Junge` (R$ 100 e R$ 2.100 e R$ 4.500 — transferências de
naturezas diferentes) e afetaria qualquer favorecido que receba mais de um tipo de pagamento.

## Decisão

O agrupamento passa a ser por **`(favorecido/merchant, cluster de valor)`**, e só depois se avalia a
regularidade do intervalo:

```
1. Agrupa por counterparty_id / merchant_id
2. DENTRO de cada grupo, clusteriza por valor:
   dois valores no mesmo cluster se |a−b| / min(a,b) <= 15%
   (clustering aglomerativo simples de 1 dimensão — ~20 linhas de PHP)
3. Para cada cluster com >= 3 ocorrências (ou >= 2 com intervalo mensal exato):
   avalia regularidade do intervalo → sugere recorrência
4. Ocorrências no mesmo dia de clusters distintos são recorrências distintas
```

Aplicado aos dados reais, o resultado correto:

| Favorecido | Cluster | Ocorr. | Intervalo | Veredito |
|-----------|---------|-------:|-----------|----------|
| Escola Riacho Doce | R$ 1.419,00 | 2 | 30 dias | ✅ mensal, dia 10 |
| Escola Riacho Doce | R$ 745,00 | 2 | 30 dias | ✅ mensal, dia 10 |
| Escola Riacho Doce | R$ 236,73 | 2 | 30 dias | ✅ mensal, dia 10 |
| Escola Riacho Doce | R$ 50,00 | 1 | — | ✅ pontual, não sugere |
| Veronica Lima do Vale | R$ 260,00 | 8 | 7 dias | ✅ semanal |
| Banco Volkswagen | R$ 2.284,43 | 2 | 30 dias | ✅ mensal, dia 15 |
| Recanto das Araras | 475,01→540,98 | 2 | 30 dias | ✅ mensal + reajuste 13,9% |

## Nota sobre o limiar de ocorrências

O desenho original exigia 3 ocorrências. Com **2 meses de extrato**, uma recorrência mensal só tem 2.
Regra ajustada: **2 ocorrências bastam quando o intervalo é 28–33 dias e o valor está dentro da
tolerância** (mensal é o caso dominante e o mais previsível). Para intervalos semanais/quinzenais,
mantém-se 3 — há mais ruído nessa faixa.

## Consequências

- `PatternDetector` ganha um passo de clustering. `recurrence_suggestions.signature` passa a incluir a
  faixa de valor do cluster, para que três sugestões distintas do mesmo favorecido coexistam.
- Casos de teste permanentes a partir dos dados reais: Escola Riacho Doce (3 clusters mesmo dia),
  Veronica (semanal), Recanto das Araras (reajuste), Celesc (variação −79% → `needs_review`).
- Sem esta correção, o sistema silenciosamente não sugeriria as recorrências de maior valor.
