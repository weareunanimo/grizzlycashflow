# 05 — Contrato da API v1

## 1. Princípios

- **Base:** `/api/v1`. Versão na URL: quebrar contrato exige `/v2`, e `/v1` continua funcionando.
- **JSON** em tudo (exceto upload `multipart/form-data` e download de anexo).
- **Autenticação:** cookie de sessão `HttpOnly; Secure; SameSite=Lax` + **CSRF** em toda mutação.
  Não usamos JWT: com um cliente que é o próprio site, JWT só adiciona risco de token vazado e
  impossibilidade de revogação. Para o WhatsApp/integrações (V3) usaremos **API tokens** dedicados
  com escopo, tabela própria e revogação individual.
- **Dinheiro na API:** sempre `*_cents` inteiro. O front formata. Nunca string com "R$".
- **Datas:** `YYYY-MM-DD`; timestamps ISO-8601 UTC (`2026-08-03T14:22:10Z`).
- **Erros:** RFC 7807 `application/problem+json`.
- **Idempotência:** mutações que criam algo aceitam header `Idempotency-Key`.
- **Paginação:** cursor (`?cursor=...&limit=50`) — offset degrada com 10 anos de histórico.
- **Rate limit:** por sessão e por IP; headers `X-RateLimit-*`.

### Formato de erro

```json
{
  "type": "https://grizzly.app/errors/validation",
  "title": "Dados inválidos",
  "status": 422,
  "detail": "O campo amount_cents deve ser maior que zero.",
  "errors": { "amount_cents": ["deve ser maior que zero"] },
  "request_id": "01J9XK2M8P4QRSTVWXYZ"
}
```

### Envelope de listagem

```json
{
  "data": [ ... ],
  "meta": { "count": 50, "next_cursor": "eyJpZCI6MTIzfQ", "total_cents": -1284590 }
}
```

---

## 2. Autenticação e sessão

| Método | Rota | Descrição |
|--------|------|-----------|
| `POST` | `/auth/login` | `{email, password}` → 200 ou 202 (`totp_required`) |
| `POST` | `/auth/totp` | `{code}` → completa o login |
| `POST` | `/auth/logout` | encerra a sessão atual |
| `GET` | `/auth/me` | usuário + settings + CSRF token |
| `GET` | `/auth/sessions` | dispositivos ativos |
| `DELETE` | `/auth/sessions/{id}` | revoga sessão |
| `POST` | `/auth/password` | troca de senha (exige senha atual) |
| `POST` | `/auth/totp/setup` · `/auth/totp/confirm` · `DELETE /auth/totp` | gestão do 2FA |
| `POST` | `/auth/forgot` · `/auth/reset` | recuperação por e-mail |

Não existe `POST /auth/register`. O usuário inicial é criado por CLI (`bin/console user:create`).

---

## 3. Movimentações

```
GET /transactions
  ?from=2026-08-01&to=2026-08-31
  &account_id=1&credit_card_id=2&category_id=5&merchant_id=9&recurrence_id=3
  &direction=out&payment_method=pix&status=cleared&source=pdf
  &needs_review=1&min_cents=1000&max_cents=50000
  &q=texto&tag=carro&has_attachment=1
  &sort=-occurred_on&cursor=...&limit=50
```

```jsonc
// item de resposta
{
  "id": 8231,
  "occurred_on": "2026-08-02",
  "occurred_at": "2026-08-02T19:41:00Z",
  "cash_effect_on": "2026-09-10",
  "direction": "out",
  "amount_cents": 8900,
  "description": "Angeloni",
  "raw_description": "MERCADO ANGELONI 442 FLORIANOPOLIS",
  "payment_method": "credit_card",
  "status": "cleared",
  "account": { "id": 2, "name": "Nubank Cartão", "type": "credit_card" },
  "category": { "id": 12, "name": "Mercado", "parent": { "id": 3, "name": "Alimentação" }, "color": "#22c55e" },
  "merchant": { "id": 9, "canonical_name": "Angeloni" },
  "counterparty": null,
  "tags": ["casa"],
  "installment": { "purchase_id": null, "number": null, "of_total": null },
  "recurrence": null,
  "classification": { "confidence": 0.97, "source": "memory", "needs_review": false },
  "evidences": [ { "id": 55, "source": "pdf", "role": "primary", "document_id": 120 } ],
  "attachments_count": 1,
  "notes": null,
  "created_at": "2026-08-03T02:11:04Z",
  "updated_at": "2026-08-03T02:11:04Z"
}
```

| Método | Rota | Descrição |
|--------|------|-----------|
| `POST` | `/transactions` | cria manualmente (passa pelo Reconciler → pode responder `409 possible_duplicate`) |
| `GET` | `/transactions/{id}` | detalhe + evidências + anexos + histórico de auditoria |
| `PATCH` | `/transactions/{id}` | edição parcial; toda mudança vai para `audit_log` |
| `DELETE` | `/transactions/{id}` | soft delete |
| `POST` | `/transactions/{id}/restore` | desfaz |
| `POST` | `/transactions/bulk` | `{ids:[], set:{category_id, tags, needs_review:false}}` |
| `POST` | `/transactions/{id}/split` | divide em várias categorias |
| `POST` | `/transactions/{id}/merge` | `{into_id}` — consolida, preservando evidências |
| `POST` | `/transactions/{id}/attachments` | `multipart` → anexa documento |
| `GET` | `/transactions/{id}/history` | trilha de auditoria legível |
| `GET` | `/transactions/review` | fila de revisão (baixa confiança + possíveis duplicatas) |

Ao criar/editar, resposta inclui `suggestions`:

```jsonc
"suggestions": [
  { "type": "rule", "id": 77, "summary": "Toda compra na Shell → Combustível", "confidence": 0.93 },
  { "type": "recurrence", "id": 12, "summary": "Este gasto parece recorrente (mensal, R$ 129,90)", "confidence": 0.88 }
]
```

---

## 4. Contas, instituições e cartões

| Método | Rota | Descrição |
|--------|------|-----------|
| `GET/POST/PATCH/DELETE` | `/accounts[/{id}]` | contas; `GET` traz `current_balance_cents` |
| `GET/POST/PATCH` | `/institutions[/{id}]` | |
| `GET` | `/cards` | cartões + limite usado/livre + fatura atual + comprometido total |
| `POST/PATCH` | `/cards[/{id}]` | limite, fechamento, vencimento, conta pagadora |
| `GET` | `/cards/{id}/statements?from&to` | faturas (importadas e projetadas) |
| `GET` | `/cards/{id}/statements/{ref}` | fatura de um mês (`ref` = `2026-08`) com itens |
| `POST` | `/cards/{id}/statements/{ref}/pay` | registra pagamento → cria transação na conta pagadora e casa a projeção |
| `GET` | `/cards/{id}/forecast?months=12` | ⭐ projeção mês a mês |
| `GET` | `/cards/{id}/committed` | total comprometido futuro, por mês e por compra |

### ⭐ `GET /cards/{id}/forecast?months=12`

```jsonc
{
  "card": { "id": 2, "name": "Nubank", "credit_limit_cents": 1500000,
            "used_cents": 432100, "available_cents": 1067900,
            "closing_day": 3, "due_day": 10 },
  "committed_total_cents": 430000,
  "months": [
    { "reference_month": "2026-08", "due_date": "2026-08-10", "status": "closed",
      "actual_total_cents": 289050,
      "installments_cents": 105000, "recurring_cents": 39900, "other_cents": 144150,
      "installments_count": 4 },
    { "reference_month": "2026-09", "due_date": "2026-09-10", "status": "projected",
      "committed_cents": 144900,
      "installments_cents": 105000, "recurring_cents": 39900,
      "estimated_variable_cents": 132000,
      "projected_total_cents": 276900,
      "confidence": 0.72,
      "installments": [
        { "purchase_id": 44, "description": "Notebook Dell", "number": 6, "of_total": 12,
          "amount_cents": 45000, "remaining_after_cents": 270000 }
      ] }
  ]
}
```

### Compras e parcelas

| Método | Rota | Descrição |
|--------|------|-----------|
| `GET` | `/purchases?card_id&status=active&has_remaining=1` | compras parceladas em aberto |
| `GET` | `/purchases/{id}` | compra + as N parcelas + histórico |
| `POST` | `/purchases` | cadastro manual de parcelado (para compras fora do cartão também) |
| `PATCH` | `/purchases/{id}` | corrige descrição, categoria, nº de parcelas → **recalcula o plano** |
| `POST` | `/purchases/{id}/cancel` | estorno/cancelamento → parcelas futuras `canceled` |
| `GET` | `/installments?from=2026-09&to=2027-08&status=projected` | linha do tempo de parcelas |
| `PATCH` | `/installments/{id}` | ajusta valor/mês de uma parcela específica |

---

## 5. Documentos e importação

| Método | Rota | Descrição |
|--------|------|-----------|
| `POST` | `/documents` | `multipart`: `file`, `kind?`, `account_id?`, `credit_card_id?` → `202` |
| `GET` | `/documents?status&kind&cursor` | lista |
| `GET` | `/documents/{id}` | status do processamento, erro, batch gerado |
| `POST` | `/documents/{id}/unlock` | `{password}` para PDF protegido |
| `POST` | `/documents/{id}/reprocess` | reprocessa com parser atualizado (usa `extracted_text` guardado) |
| `DELETE` | `/documents/{id}` | descarta (soft) |
| `GET` | `/documents/{id}/download` | stream autenticado — **nunca** URL pública |
| `GET` | `/documents/{id}/preview` | thumbnail/imagem inline |

### Fluxo de importação (preview → commit)

| Método | Rota | Descrição |
|--------|------|-----------|
| `GET` | `/imports?status` | lotes |
| `GET` | `/imports/{batch}/preview` | ⭐ tudo que será importado, antes de gravar |
| `PATCH` | `/imports/{batch}/rows/{row}` | ajusta uma linha (categoria, decisão, valor, conta) |
| `POST` | `/imports/{batch}/rows/bulk` | decisão em massa |
| `POST` | `/imports/{batch}/commit` | grava em transação atômica |
| `POST` | `/imports/{batch}/rollback` | desfaz um commit inteiro (janela de 7 dias) |
| `POST` | `/imports/csv/detect` | envia CSV → devolve layout detectado + perfil sugerido |
| `GET/POST/PATCH/DELETE` | `/importer-profiles[/{id}]` | perfis de layout de CSV |

### ⭐ `GET /imports/{batch}/preview`

```jsonc
{
  "batch": { "id": 31, "importer_key": "nubank_statement_pdf", "status": "previewed",
             "document": { "id": 120, "original_name": "fatura-agosto.pdf" },
             "credit_card_id": 2, "statement": { "reference_month": "2026-08", "due_date": "2026-08-10" } },
  "validation": {
    "statement_total_cents": 289050,
    "items_sum_cents": 289050,
    "matches": true,
    "warnings": []
  },
  "stats": { "rows": 42, "to_create": 38, "to_merge": 2, "to_skip": 2, "conflicts": 0,
             "new_purchases": 1, "recognized_purchases": 3 },
  "rows": [
    { "id": 900, "line_no": 12,
      "raw": { "date": "02/08", "description": "MERCADO ANGELONI 442", "amount": "89,00" },
      "normalized": { "occurred_on": "2026-08-02", "amount_cents": 8900, "direction": "out",
                      "description": "Angeloni", "payment_method": "credit_card" },
      "category": { "id": 12, "name": "Mercado" }, "category_confidence": 0.97,
      "proposed_decision": "create", "dedup_candidates": [] },

    { "id": 901, "line_no": 13,
      "raw": { "date": "05/08", "description": "NOTEBOOK DELL PARC 05/12", "amount": "450,00" },
      "normalized": { "occurred_on": "2026-08-05", "amount_cents": 45000, "direction": "out" },
      "installment_info": { "number": 5, "of_total": 12, "purchase_id": 44,
                            "recognized": true, "projects_future": 7,
                            "future_committed_cents": 315000 },
      "proposed_decision": "create", "dedup_candidates": [] },

    { "id": 902, "line_no": 21,
      "raw": { "date": "07/08", "description": "UBER TRIP", "amount": "23,40" },
      "proposed_decision": "merge",
      "dedup_candidates": [
        { "transaction_id": 8102, "score": 0.94,
          "reasons": ["same_amount", "same_date", "merchant_similar_0.98", "other_source:ocr"],
          "existing": { "occurred_on": "2026-08-07", "amount_cents": 2340, "source": "ocr" } }
      ] }
  ]
}
```

---

## 6. Captura rápida (texto, áudio, imagem)

| Método | Rota | Descrição |
|--------|------|-----------|
| `POST` | `/quick/text` | `{text:"Paguei 89 reais no mercado"}` → candidato interpretado |
| `POST` | `/quick/audio` | `multipart`: `audio`, `transcript?` (se o navegador transcreveu) |
| `POST` | `/quick/image` | `multipart`: `image` (print de notificação) |
| `POST` | `/quick/{id}/confirm` | confirma/ajusta → grava |
| `DELETE` | `/quick/{id}` | descarta o candidato |

```jsonc
// resposta de POST /quick/text
{
  "candidate_id": "01J9XK...",
  "interpretation": {
    "direction": "out", "amount_cents": 8900, "occurred_on": "2026-08-03",
    "description": "Mercado", "payment_method": null,
    "category": { "id": 12, "name": "Mercado" },
    "account_id": 1
  },
  "confidence": { "overall": 0.86, "amount": 0.99, "date": 0.90, "category": 0.82, "account": 0.55 },
  "engine": "deterministic",
  "missing": ["payment_method"],
  "dedup": { "decision": "create", "candidates": [] },
  "needs_confirmation": true
}
```

---

## 7. Classificação: categorias, estabelecimentos, regras

| Método | Rota | Descrição |
|--------|------|-----------|
| `GET` | `/categories?tree=1` | árvore de 2 níveis com totais do período |
| `POST/PATCH/DELETE` | `/categories[/{id}]` | delete = arquivar se tiver histórico |
| `POST` | `/categories/{id}/merge` | `{into_id}` — reclassifica em lote |
| `GET` | `/merchants?q=&sort=-total_spent` | estabelecimentos |
| `PATCH` | `/merchants/{id}` | nome canônico, categoria padrão |
| `POST` | `/merchants/{id}/merge` | `{into_id}` — unifica aliases |
| `GET` | `/counterparties` · `PATCH /counterparties/{id}` | pessoas/favorecidos |
| `GET` | `/rules` | ordenadas por prioridade, com `match_count` |
| `POST/PATCH/DELETE` | `/rules[/{id}]` | |
| `POST` | `/rules/reorder` | `{ids:[...]}` nova prioridade |
| `POST` | `/rules/{id}/test` | dry-run: quantas transações históricas casariam |
| `POST` | `/rules/{id}/apply` | aplica retroativamente (com preview e auditoria) |
| `GET` | `/rules/suggestions` | sugestões pendentes |
| `POST` | `/rules/suggestions/{id}/accept` · `/dismiss` | |
| `GET` | `/tags` · `POST/PATCH/DELETE /tags[/{id}]` | |

---

## 8. Recorrências

| Método | Rota | Descrição |
|--------|------|-----------|
| `GET` | `/recurrences?status=active&kind=subscription` | lista com métricas |
| `POST/PATCH` | `/recurrences[/{id}]` | **não gera lançamento** (I5) |
| `POST` | `/recurrences/{id}/cancel` | `{ended_on, reason}` |
| `GET` | `/recurrences/{id}` | detalhe completo |
| `GET` | `/recurrences/{id}/occurrences?from&to` | esperado vs. realizado |
| `POST` | `/recurrences/{id}/link` | `{transaction_id}` — vincula manualmente |
| `DELETE` | `/recurrences/{id}/link/{transaction_id}` | desvincula |
| `GET` | `/recurrences/suggestions` · `POST .../accept` · `/dismiss` | |
| `GET` | `/recurrences/summary` | total mensal, nº de assinaturas, maior recorrente |

```jsonc
// GET /recurrences/{id}
{
  "id": 12, "name": "Internet Vivo", "kind": "bill", "frequency": "monthly", "day_of_month": 15,
  "status": "active", "started_on": "2023-04-15",
  "metrics": {
    "months_active": 40,
    "total_paid_cents": 489600,
    "average_amount_cents": 12240,
    "current_amount_cents": 12990,
    "first_amount_cents": 9990,
    "total_increase_pct": 30.03,
    "last_increase": { "changed_on": "2026-02-15", "from_cents": 11990, "to_cents": 12990, "delta_pct": 8.34 },
    "payments_late": 2,
    "payments_missed": 0,
    "next_expected_on": "2026-09-15"
  },
  "price_changes": [
    { "changed_on": "2024-03-15", "from_cents": 9990,  "to_cents": 10990, "delta_pct": 10.01 },
    { "changed_on": "2025-04-15", "from_cents": 10990, "to_cents": 11990, "delta_pct": 9.10 },
    { "changed_on": "2026-02-15", "from_cents": 11990, "to_cents": 12990, "delta_pct": 8.34 }
  ]
}
```

---

## 9. Dashboards e fluxo de caixa

| Método | Rota | Descrição |
|--------|------|-----------|
| `GET` | `/dashboard/overview?period=2026-08` | tudo da tela inicial em **1 request** |
| `GET` | `/dashboard/categories?from&to&direction=out&depth=2` | rosca + tabela |
| `GET` | `/dashboard/monthly?months=13` | barras entradas×saídas |
| `GET` | `/dashboard/comparison?a=2026-07&b=2026-08` | comparativo com variação % |
| `GET` | `/dashboard/merchants?from&to&limit=10` | top estabelecimentos |
| `GET` | `/dashboard/heatmap?year=2026` | gasto por dia do ano |
| `GET` | `/dashboard/calendar?month=2026-09` | eventos do calendário financeiro |
| `GET` | `/cashflow?granularity=month&from=2026-01&to=2027-06` | ⭐ realizado + projetado |
| `GET` | `/cashflow/critical` | meses com risco |
| `GET` | `/networth?months=24` | patrimônio no tempo |

### ⭐ `GET /cashflow`

```jsonc
{
  "granularity": "month",
  "current_balance_cents": 742300,
  "buckets": [
    { "bucket_start": "2026-08-01", "is_projection": false,
      "in_cents": 1250000, "out_cents": 1102400,
      "closing_balance_cents": 742300, "risk_level": "ok" },
    { "bucket_start": "2026-09-01", "is_projection": true,
      "in_cents": 1250000,
      "out_cents": 1489000,
      "breakdown": { "card_committed_cents": 430000, "card_estimated_cents": 132000,
                     "recurring_cents": 687000, "planned_cents": 240000 },
      "closing_balance_cents": 503300, "risk_level": "attention", "confidence": 0.78 },
    { "bucket_start": "2026-10-01", "is_projection": true,
      "in_cents": 1250000, "out_cents": 1810000,
      "closing_balance_cents": -56700, "risk_level": "critical", "confidence": 0.71 }
  ],
  "alerts": [
    { "type": "negative_forecast", "bucket_start": "2026-10-01",
      "message": "Seu saldo projetado fica negativo em outubro (R$ -567,00).",
      "severity": "critical" }
  ]
}
```

---

## 10. Insights, busca, exportação, saúde

| Método | Rota | Descrição |
|--------|------|-----------|
| `GET` | `/insights?status=new` | feed de insights |
| `POST` | `/insights/{id}/dismiss` · `/pin` | |
| `POST` | `/insights/generate` | força regeração (enfileira) |
| `GET` | `/search?q=...` | busca global (transações, merchants, recorrências, documentos) |
| `GET` | `/export/transactions.csv?from&to` | exportação CSV |
| `GET` | `/export/full.json` | ⭐ dump completo dos dados do usuário (anti-lock-in / LGPD) |
| `GET` | `/settings` · `PATCH /settings` | preferências (tema, limiares de confiança, teto de IA) |
| `GET` | `/health` | público-mínimo: `{ok:true}` |
| `GET` | `/admin/health` | autenticado: fila, cron, backup, custo de IA, taxa de automação |
| `GET` | `/admin/ai-usage?month=2026-08` | custo por finalidade e por modelo |

```jsonc
// GET /insights
{
  "data": [
    { "id": 501, "type": "category_spike", "severity": "warn",
      "title": "Você gastou 18% mais com restaurantes",
      "body": "R$ 842,00 em agosto contra R$ 713,00 na média dos 3 meses anteriores.",
      "metrics": { "category_id": 14, "current_cents": 84200, "baseline_cents": 71300, "delta_pct": 18.09 },
      "action_url": "/transactions?category_id=14&from=2026-08-01&to=2026-08-31",
      "generated_by": "rule", "confidence": 1.0, "created_at": "2026-09-01T03:12:00Z" },
    { "id": 502, "type": "committed_total", "severity": "info",
      "title": "Você possui R$ 4.300,00 comprometidos em parcelas futuras",
      "metrics": { "committed_cents": 430000, "months_ahead": 7, "purchases": 5 },
      "action_url": "/cards" }
  ]
}
```

---

## 11. Webhook do WhatsApp (V3, projetado agora)

| Método | Rota | Descrição |
|--------|------|-----------|
| `GET` | `/webhooks/whatsapp` | verificação do token (handshake da Meta) |
| `POST` | `/webhooks/whatsapp` | recebe mensagens; valida `X-Hub-Signature-256`; responde `200` em < 2s e enfileira |

O controller **só** valida assinatura, resolve o número → `user_id` (whitelist), baixa a mídia,
cria `document` e enfileira. Toda a inteligência é a mesma do upload web — nenhum código de
domínio novo (ADR-0017).

---

## 12. Compatibilidade de longo prazo

| Regra | Motivo |
|-------|--------|
| Nunca remover campo de resposta em `v1` | clientes antigos (PWA em cache, integrações) quebram |
| Adicionar campo é sempre permitido | clientes ignoram o que não conhecem |
| Novo valor de enum vem documentado; cliente trata desconhecido como `other` | evolução sem `v2` |
| Mudança incompatível → `/api/v2`, `v1` mantido por ≥ 12 meses | |
| Contrato validado por testes de Feature (snapshot de resposta) | evita quebra acidental |
