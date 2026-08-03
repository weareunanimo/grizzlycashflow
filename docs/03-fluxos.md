# 03 — Fluxogramas

## 1. Fluxo macro do produto

```mermaid
flowchart LR
  subgraph Entrada
    P[PDF fatura]
    C[CSV banco]
    I[Print/foto]
    T[Texto]
    A[Áudio]
    W[WhatsApp V3]
    M[Manual]
  end
  P & C & I & T & A & W & M --> ING[Pipeline de Ingestão]
  ING --> LED[(Ledger)]
  ING --> CARDS[(Compras + Parcelas)]
  LED --> PROJ[Motor de Projeção]
  CARDS --> PROJ
  REC[(Recorrências)] --> PROJ
  PROJ --> DASH[Dashboards + Fluxo futuro]
  PROJ --> INS[Insights automáticos]
  LED --> INS
  DASH --> USER((Você))
  INS --> USER
  USER -.correções.-> LEARN[Aprendizado]
  LEARN -.melhora.-> ING
```

## 2. Importação de fatura de cartão (PDF) — o fluxo mais importante

```mermaid
sequenceDiagram
  autonumber
  actor U as Usuário
  participant API as API
  participant Q as Fila (MySQL)
  participant EX as Extractor PDF
  participant AI as Provedor IA
  participant CD as CardDomain
  participant RC as Reconciler
  participant DB as Banco

  U->>API: POST /api/v1/documents (fatura.pdf)
  API->>DB: sha256 já existe?
  alt arquivo idêntico já enviado
    API-->>U: 200 {document_id, status:"duplicate", batch anterior}
  else novo
    API->>DB: INSERT document(received)
    API->>Q: job document.parse
    API-->>U: 202 {document_id}
  end

  Q->>EX: extrai texto (camada de texto do PDF)
  alt PDF protegido por senha
    EX->>DB: status=needs_input (password_required)
    U->>API: POST /documents/{id}/unlock {senha}
    API->>Q: re-enfileira
  end
  alt sem camada de texto (escaneado)
    EX->>AI: PDF como documento (visão)
  else com texto
    EX->>EX: identifica emissor (fingerprint do cabeçalho)
    alt template conhecido
      EX->>EX: parser determinístico (regex + geometria) — custo zero
    else desconhecido / baixa confiança
      EX->>AI: extração estruturada (JSON schema)
    end
  end
  EX->>EX: VALIDA soma dos itens vs. total da fatura
  EX->>DB: card_statements + import_batch(previewed) + import_rows
  EX->>CD: detecta padrões de parcela ("05/12")
  CD->>CD: calcula first_reference_month = ref - (n-1) meses
  CD->>CD: group_key = hash(cartão|merchant|valor|total|1º mês)
  CD->>DB: SELECT card_purchases WHERE group_key = ?
  alt compra já conhecida
    CD->>DB: marca parcela n como billed (nada é recriado)
  else compra nova
    CD->>DB: cria card_purchase + N card_installments<br/>(1..n-1 reconstruídas, n billed, n+1..N projected)
  end
  EX->>RC: cada item → análise de duplicidade
  RC->>DB: proposed_decision por linha
  Q->>DB: document.status = parsed
  U->>API: GET /imports/{batch}/preview
  API-->>U: linhas + decisões + confiança + validação
  U->>API: POST /imports/{batch}/commit (com overrides)
  API->>DB: TRANSAÇÃO: cria/mescla/ignora + evidences + audit_log
  API->>Q: projection.rebuild + insight.generate
  API-->>U: 200 {criadas, mescladas, ignoradas}
```

## 3. Reconstrução de parcelas — o algoritmo

Entrada: fatura de **agosto/2026**, item `NOTEBOOK DELL   PARC 05/12   450,00`.

```mermaid
flowchart TD
  A["Item da fatura<br/>desc: 'NOTEBOOK DELL PARC 05/12'<br/>valor: R$ 450,00<br/>fatura ref: 2026-08"] --> B[Detecta padrão de parcela<br/>regex BR: 05/12 · PARC 5 DE 12 · 5 de 12]
  B --> C{Achou<br/>n de N?}
  C -->|não| D[Compra à vista<br/>N=1, n=1]
  C -->|sim| E["n=5, N=12<br/>merchant normalizado = 'notebook dell'"]
  E --> F["first_reference_month<br/>= 2026-08 menos (5-1) meses<br/>= 2026-04"]
  F --> G["group_key = sha256(<br/> card_id | 'notebook dell' | 45000 | 12 | 2026-04)"]
  G --> H{Existe<br/>card_purchase<br/>com esse group_key?}
  H -->|sim| I["Compra reconhecida<br/>→ apenas marca parcela 5 como 'billed'<br/>→ vincula ao statement_id<br/>→ NÃO cria nada novo (Invariante I10)"]
  H -->|não| J["Cria card_purchase:<br/>total = 45000 x 12 = R$ 5.400<br/>first_reference_month = 2026-04"]
  J --> K["Cria 12 card_installments:"]
  K --> L["1..4 → status=billed<br/>is_reconstructed=1<br/>ref: 04,05,06,07/2026"]
  K --> M["5 → status=billed<br/>statement_id vinculado<br/>ref: 08/2026"]
  K --> N["6..12 → status=projected<br/>ref: 09/2026 .. 03/2027"]
  L & M & N --> O["✅ R$ 3.150 de compromisso futuro<br/>aparecem no fluxo de caixa<br/>sem digitar nada"]
  I --> O
  D --> P[Fluxo normal de transação]
```

**Casos de borda tratados explicitamente:**

| Caso | Tratamento |
|------|-----------|
| Parcelas com centavos diferentes (`R$ 450,01` na 1ª) | `group_key` usa o valor da parcela **corrente**; se não casar, tenta match tolerante (±2 centavos) antes de criar compra nova |
| Fatura pulada (importei março e depois julho) | As parcelas intermediárias são criadas como `billed is_reconstructed=1`; ao importar as faturas do meio, elas são apenas **confirmadas** |
| Compra parcelada estornada | Item de crédito com mesmo merchant/valor → `purchase.status=refunded`, parcelas `projected` viram `canceled` |
| Parcelamento da própria fatura | `is_installment_plan=1`; não é gasto novo, é rolagem de dívida — excluído de "gastos por categoria", incluído no comprometimento |
| Juros/IOF/anuidade | Categorizados como Taxas bancárias, `installments_total=1` |
| Compra internacional | `original_amount`/`original_currency` guardados; valor em BRL é o que vale para o caixa |
| Cartão adicional / virtual | `credit_cards.is_virtual`, mesma fatura do titular via `account_id` |

## 4. Motor de decisão de duplicidade (conciliação)

```mermaid
flowchart TD
  A[CandidateTransaction] --> B{external_id<br/>presente?}
  B -->|sim| C{Existe tx com<br/>mesmo external_id?}
  C -->|sim| D[SKIP — é a mesma, certeza absoluta]
  C -->|não| E[CREATE]
  B -->|não| F[fingerprint estrito<br/>hash: conta+data+valor+direção+merchant_norm]
  F --> G{Match exato?}
  G -->|sim| H{Origem é a mesma<br/>do registro existente?}
  H -->|sim| D
  H -->|não| I[MERGE — conciliação multicanal<br/>adiciona evidence, enriquece campos vazios]
  G -->|não| J[Busca candidatos por janela:<br/>valor exato ±0 · data ±5 dias · mesma conta]
  J --> K[Score ponderado]
  K --> L{score}
  L -->|">= 0.90"| I
  L -->|"0.60 – 0.89"| M["ASK — 'Encontramos uma movimentação<br/>muito parecida'<br/>substituir / ignorar / manter ambas"]
  L -->|"< 0.60"| E
  M --> N[dedup_reviews pendente]
  I --> O[transaction_evidences + audit_log]
  E --> O
```

**Pesos do score** (configuráveis em `user_settings`, calibrados com dados reais):

| Sinal | Peso | Observação |
|-------|------|-----------|
| Valor idêntico | 0,35 | valor diferente ⇒ score cai a quase zero |
| Data idêntica | 0,20 | ±1 dia: 0,15 · ±3 dias: 0,08 · ±5: 0,03 |
| Merchant/favorecido similar (trigram) | 0,20 | similaridade ≥ 0,85 |
| Mesmo método de pagamento | 0,10 | |
| Mesma conta/cartão | 0,10 | |
| Mesma parcela (n/N + group_key) | 0,25 | sinal decisivo em fatura |
| Descrição similar | 0,05 | |
| **Penalidade:** mesma origem e mesmo documento | −0,50 | duas linhas do mesmo CSV geralmente são gastos reais distintos |

## 5. Cascata de categorização (barato → caro)

```mermaid
flowchart TD
  A[CandidateTransaction] --> R{1. Regra do usuário<br/>por prioridade}
  R -->|match| RD["categoria · confiança 1.00<br/>source=rule · custo R$ 0"]
  R -->|sem match| M{2. Memória de merchant<br/>merchant_category_stats}
  M -->|">= 3 ocorrências<br/>e >= 80% na mesma categoria"| MD["categoria · confiança 0.95<br/>source=memory · custo R$ 0"]
  M -->|inconclusivo| S{3. Dicionário semente<br/>60+ marcas BR}
  S -->|match| SD["categoria · confiança 0.85<br/>source=seed · custo R$ 0"]
  S -->|sem match| N{4. Naive Bayes<br/>classifier_tokens}
  N -->|">= 0.75"| ND["categoria · confiança calculada<br/>source=stats · custo R$ 0"]
  N -->|"< 0.75"| L[5. LLM em lote<br/>até 40 itens por chamada]
  L --> LD["categoria + confiança do modelo<br/>source=ai · custo ~US$ 0,0004/item"]
  RD & MD & SD & ND & LD --> T{confiança<br/>>= 0.70?}
  T -->|sim| OK[Grava classificada]
  T -->|não| RV[Grava com needs_review=1<br/>aparece na fila de revisão]
  RV --> U[Usuário corrige]
  U --> FB[category_feedback +<br/>classifier_tokens +<br/>merchant_category_stats]
  FB --> SUG{Padrão claro?<br/>>= 3 vezes mesmo merchant<br/>→ mesma categoria}
  SUG -->|sim| PROP["rule_suggestions:<br/>'Percebi que toda compra na Shell<br/>é Combustível. Criar regra?'"]
```

**Efeito esperado ao longo do tempo** (é o que a métrica `metrics_daily` vai provar):

| Mês de uso | % resolvido sem IA | % que precisa de revisão manual |
|------------|--------------------|--------------------------------|
| 1 | ~55% (regras semente) | ~25% |
| 3 | ~85% | ~8% |
| 6+ | **~97%** | **< 3%** |

## 6. Casamento de recorrência (ADR-0010)

```mermaid
flowchart TD
  A[Transação sendo commitada] --> B[Busca recorrências ativas candidatas<br/>mesma direção · conta/cartão compatível]
  B --> C{Match de identidade?<br/>merchant OU counterparty OU<br/>match_config.description_contains}
  C -->|não| D[Sem recorrência<br/>→ avalia sugestão de novo padrão]
  C -->|sim| E{Valor dentro da<br/>tolerância?<br/>±amount_tolerance_pct}
  E -->|não| F{Diferença consistente<br/>com reajuste?<br/>+3% a +30%}
  F -->|sim| G["Casa + registra<br/>recurrence_price_changes<br/>💡 'Internet Vivo subiu 8,2%'"]
  F -->|não| H[Casa com confiança baixa<br/>needs_review]
  E -->|sim| I{Data dentro da janela<br/>do período esperado?<br/>±date_tolerance_days}
  I -->|sim| J[occurrence.status = matched]
  I -->|"depois da janela"| K[occurrence.status = late<br/>💡 'Pagamento em atraso']
  J & K & G --> L["Vincula transaction.recurrence_id<br/>NÃO cria lançamento nenhum (I5)"]
  D --> M[Detector de padrão:<br/>3+ transações · mesmo merchant ·<br/>intervalo regular ±5d · valor estável ±15%]
  M -->|padrão| N["recurrence_suggestions:<br/>'Este gasto parece recorrente.<br/>Marcar como recorrente?'"]
```

Job noturno `recurrence.sweep`: para cada recorrência ativa, gera as `occurrences` esperadas
do período e marca como `missed` as que passaram da janela sem casamento → alimenta insights
de "boleto não pago" e a projeção de saída futura.

## 7. Motor de fluxo de caixa projetado

```mermaid
flowchart TD
  subgraph "Fontes de eventos de caixa"
    A1["Realizado<br/>transactions cleared<br/>em contas (não-cartão)"]
    A2["Confirmado<br/>transactions pending"]
    A3["Comprometido em cartão<br/>card_installments<br/>projected + billed"]
    A4["Recorrências esperadas<br/>recurrence_occurrences<br/>status=expected"]
    A5["Estimativa variável<br/>mediana 3m do gasto<br/>não-parcelado e não-recorrente"]
    A6["Planejado manual<br/>eventos futuros criados à mão"]
  end
  A1 --> N[Normaliza em CashEvent<br/>date · direction · amount · confidence · origin]
  A2 --> N
  A3 --> AGG["Agrega por cartão e mês<br/>→ 1 evento na due_date<br/>na conta pagadora (I4)"]
  A5 --> AGG
  A4 --> DEDUP
  AGG --> DEDUP{"Já existe fato<br/>que substitui<br/>esta projeção?"}
  N --> DEDUP
  A6 --> DEDUP
  DEDUP -->|sim| DROP[Descarta a projeção<br/>o fato ganha sempre]
  DEDUP -->|não| BUCKET[Agrupa em buckets<br/>dia / semana / mês]
  BUCKET --> RUN["Saldo acumulado<br/>partindo do saldo atual real"]
  RUN --> RISK{"Saldo projetado<br/>fica negativo?"}
  RISK -->|sim| ALERT["risk_level=critical<br/>💡 'Seu fluxo ficará negativo<br/>em setembro'"]
  RISK -->|"< 1 mês de reserva"| WARN[risk_level=attention]
  RISK -->|não| OK[risk_level=ok]
  ALERT & WARN & OK --> SNAP[(cashflow_snapshots)]
  SNAP --> UI[Gráficos + tabela + calendário]
```

## 8. Ingestão por texto, áudio e print

```mermaid
flowchart TD
  subgraph Texto
    T1["'Paguei 89 reais no mercado'"] --> T2[Parser determinístico BR:<br/>valor · verbo · data relativa · palavra-chave]
    T2 --> T3{Confiança<br/>>= 0.80?}
    T3 -->|sim| T4[CandidateTransaction<br/>custo R$ 0]
    T3 -->|não| T5[LLM: extração estruturada]
    T5 --> T4
  end
  subgraph Áudio
    A1[Botão de gravar<br/>MediaRecorder → opus] --> A2{Web Speech API<br/>disponível?}
    A2 -->|sim| A3[Transcreve no navegador<br/>custo R$ 0]
    A2 -->|não| A4[Upload → API de transcrição<br/>~US$ 0,0002/áudio]
    A3 & A4 --> A5[Transcrição] --> T2
    A1 -.-> A6[(Áudio guardado<br/>como document)]
  end
  subgraph Print
    P1[Foto/print da notificação] --> P2[Compressão no cliente<br/>máx 1600px · WebP]
    P2 --> P3[LLM com visão:<br/>extrai valor · data · hora · descrição<br/>instituição · favorecido · tipo]
    P3 --> P4{JSON válido<br/>e valor achado?}
    P4 -->|não| P5[needs_input:<br/>usuário completa o que faltou]
    P4 -->|sim| T4
  end
  T4 --> CLASS[Cascata de categorização]
  CLASS --> CONF["Confirmação rápida na UI:<br/>chips editáveis + confiança<br/>1 toque para salvar"]
  CONF --> COMMIT[Reconciler → commit]
```

## 9. Estados de um documento

```mermaid
stateDiagram-v2
  [*] --> received: upload
  received --> processing: job claimed
  processing --> needs_input: senha do PDF · dado ilegível
  needs_input --> processing: usuário responde
  processing --> parsed: extração ok
  processing --> failed: erro definitivo (5 tentativas)
  failed --> processing: retry manual
  parsed --> imported: batch commitado
  parsed --> discarded: usuário descarta
  imported --> [*]
  note right of imported
    Documento é imutável e
    permanece como evidência
    para sempre
  end note
```

## 10. Ciclo do cron (a cada minuto)

```mermaid
flowchart LR
  T[cron 1/min] --> A[queue:work<br/>até 5 jobs · máx 50s]
  T --> B{minuto % 15 == 0?}
  B -->|sim| C[watchdog: jobs travados<br/>→ requeue]
  T --> D{03:10?}
  D -->|sim| E[recurrence.sweep]
  D -->|sim| F[projection.rebuild<br/>+ snapshots sujos]
  D -->|sim| G[insight.generate]
  D -->|sim| H[backup.run]
  D -->|sim| I[metrics.compute]
  D -->|sim| J[cleanup: logs · sessões<br/>jobs done > 30d]
  T --> K{08:00?}
  K -->|sim| L[Alertas: fatura fechando ·<br/>boleto vencendo · mês crítico]
```
