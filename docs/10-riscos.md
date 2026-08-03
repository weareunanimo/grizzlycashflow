# 10 — Riscos

Escala: Probabilidade (P) e Impacto (I) de 1 (baixo) a 5 (crítico). **Severidade = P × I.**
Ordenado por severidade. Cada risco tem mitigação **já embutida na arquitetura** — não é lista de boas intenções.

---

## 🔴 Severidade alta (≥ 12)

### R01 — Parser de fatura extrai valores errados silenciosamente · P4 · I5 · **20**
O pior risco do projeto: dados errados que parecem certos contaminam anos de histórico e decisões.

**Mitigação:**
- Validação cruzada obrigatória `soma(itens) == total da fatura`; sem fechar, **nunca** commit automático
- Nenhuma importação grava sem preview aprovado
- `documents.extracted_text` permite reprocessar tudo quando o parser melhorar
- Fixtures de faturas reais anonimizadas em CI — precisão nunca regride
- `card_statements.reconciled` exposto na UI e em `/admin/health`

**Risco residual:** fatura com layout novo e total que "fecha por coincidência". Aceito, coberto pelo preview.

---

### R02 — Duplicatas apesar do sistema de conciliação · P3 · I5 · **15**
Viola o requisito nº 1 do PO e destrói a confiança no saldo.

**Mitigação:**
- Três níveis: constraint de arquivo (sha256) → constraint de identidade (external_id, row_hash, group_key) → scoring
- Invariantes I1, I2, I10 como testes automatizados que **falham o build**
- Rollback de lote (7 dias) e merge reversível
- Relatório "possíveis duplicatas" na tela de Revisão, sempre visível
- Cada "manter ambas" do usuário calibra o scorer

---

### R03 — Bug na projeção de fluxo de caixa (dupla contagem de cartão) · P3 · I5 · **15**
Se cartão for contado como transação individual **e** como pagamento de fatura, o futuro fica 2× errado —
exatamente a informação que motivou o projeto.

**Mitigação:**
- Invariante **I4** explícita, testada: transação de cartão nunca entra no caixa individualmente
- Motor único (`CashFlowEngine`) — nenhuma outra classe soma caixa
- Teste de conferência: soma da projeção vs. soma dos componentes
- `metrics_daily.forecast_error_pct` compara projeção com realizado todo mês; erro crescente = alarme

---

### R04 — Custo de IA sai de controle · P3 · I4 · **12**
Loop de retry, arquivo gigante ou bug pode multiplicar chamadas.

**Mitigação:**
- `BudgetGuard` com teto mensal em `user_settings`; ao atingir, IA desliga e itens vão para revisão manual
- Cascata: IA é o **quinto** degrau, não o primeiro
- `ResponseCache` por `input_hash` — mesmo input nunca é pago 2×
- Máx. 5 tentativas por job, backoff exponencial
- Lotes de até 40 itens; Batch API (−50%) quando não urgente
- Custo por finalidade visível em `/admin/health` + alerta por e-mail em 80% do teto

---

### R05 — Hospedagem compartilhada não suporta algo essencial · P3 · I4 · **12**
Cron indisponível, `exec` bloqueado, limite de memória, sem `mysqldump`, sem symlink.

**Mitigação:**
- **Validar na hospedagem real na Fase 0**, antes de qualquer código de domínio
- Zero dependência de binário externo: PDF em PHP puro, backup com dump em PHP se `mysqldump` faltar
- Sem cron → fallback de "kick" via `fastcgi_finish_request()` no próprio request
- Sem SSH → rota administrativa protegida por token para migrar/deployar
- Sem DocumentRoot configurável → layout alternativo com stub em `public_html` + `.htaccess`
- Caminho de saída documentado: mover para VPS mantém 100% do código

---

## 🟡 Severidade média (6–11)

### R06 — Banco muda o layout do PDF/CSV e o parser quebra · P5 · I2 · **10**
Certeza estatística, não risco: vai acontecer.

**Mitigação:** templates versionados (`NubankStatementV3`), escolha por confiança, fallback genérico,
fallback de LLM, texto cru guardado para reprocessar, falha vira `needs_input` visível (nunca silenciosa).

### R07 — Vazamento de dados financeiros · P2 · I5 · **10**
**Mitigação:** ver `docs/11-seguranca.md` — Argon2id, sessões server-side, TOTP, CSRF, CSP, uploads fora
do webroot com autorização, prepared statements, PII redigida antes da IA, backups cifrados, sem cadastro público.

### R08 — Perda de dados · P2 · I5 · **10**
**Mitigação:** backup diário cifrado + offsite, retenção 7/4/12, **restauração testada mensalmente**
(backup não testado não existe), soft delete universal, audit log imutável, exportação completa a 1 clique.

### R09 — Classificação automática fica abaixo de 95% · P3 · I3 · **9**
**Mitigação:** cinco camadas independentes, sugestão proativa de regras, aplicação retroativa,
merge de merchants/categorias, `metrics_daily` medindo de verdade. Se travar em 90%, o gargalo é
mensurável e atacável (mais regras semente ou mais LLM). Meta é medida, não prometida.

### R10 — Complexidade excessiva torna manutenção inviável · P3 · I3 · **9**
Risco real dado o escopo (~40 tabelas, 10 módulos).

**Mitigação:** camadas com regra de dependência **testada**, 1 arquivo = 1 caso de uso, zero mágica de
framework, SQL explícito, `PROJECT.md` + ADRs como memória, glossário único, roadmap incremental,
lista explícita do que **não** faremos.

### R11 — Performance degrada com anos de histórico · P3 · I3 · **9**
**Mitigação:** read models materializados, invalidação por evento, índices desenhados a partir das
queries reais, paginação por cursor, arquivamento de jobs/logs antigos, `EXPLAIN` obrigatório em
query nova de listagem/dashboard.

### R12 — Fila travada sem ninguém perceber · P3 · I3 · **9**
**Mitigação:** watchdog requeue >10 min, máx. 5 tentativas, `/admin/health` com idade do job mais
antigo, alerta se cron não rodou em 30 min, UI mostra estado real de cada documento.

### R13 — Projeto abandonado pela metade · P3 · I3 · **9**
**Mitigação:** MVP com valor real em ~5 semanas; cada fase entrega algo usável; documentação que
permite retomar depois de meses de pausa; nenhuma fase depende de uma futura.

### R14 — Provedor de IA muda preço, API ou descontinua · P3 · I2 · **6**
**Mitigação:** `AiProvider` como interface, prompts versionados em arquivo, `NullProvider` mantém o
sistema 100% funcional sem IA, modelos configuráveis por `.env`.

### R15 — Erro em migração corrompe dados em produção · P2 · I4 · **8**
**Mitigação:** dump automático antes de migrar, `down` obrigatório, migração de dados em lotes por job,
nunca renomear/dropar coluna em uso na mesma release, staging opcional no mesmo host.

### R16 — Transcrição de áudio ruim gera lançamento errado · P3 · I2 · **6**
**Mitigação:** confirmação obrigatória na UI antes de gravar, áudio original guardado como evidência,
confiança exibida, edição em 1 toque.

---

## 🟢 Severidade baixa (≤ 5)

| # | Risco | P | I | Mitigação |
|---|-------|---|---|-----------|
| R17 | Upload malicioso (PDF/imagem com payload) | 2 | 2 | validação de MIME real, extensão forçada, fora do webroot, servido com `Content-Disposition: attachment`, nunca executável |
| R18 | ReDoS em regra de regex do usuário | 2 | 2 | validação prévia do padrão + timeout de execução |
| R19 | Web Speech API indisponível (iOS) | 4 | 1 | fallback de transcrição no servidor, previsto desde o MVP |
| R20 | Fuso horário / horário de verão em datas | 2 | 2 | `DATE` para competência, UTC em datetime, timezone do usuário só na apresentação, `Clock` injetado |
| R21 | Arredondamento de centavos em parcelas | 3 | 1 | `Money::allocate()` distribui o resto; invariante I3 testado |
| R22 | Excesso de e-mails de alerta | 3 | 1 | agrupamento diário, deduplicação por `dedupe_key`, preferências |

---

## Riscos aceitos consciamente

| Aceito | Por quê |
|--------|---------|
| Sem Open Finance no MVP/V1/V2 | custo e certificação altos; canais atuais cobrem o caso de uso |
| Sem alta disponibilidade | uso pessoal; algumas horas fora não causam prejuízo |
| Sem app nativo | PWA cobre o uso mobile |
| Sem cifragem de campo no banco | impediria busca/agregação; hospedagem + backups cifrados são a fronteira escolhida (reavaliar se virar multiusuário) |
| Estimativa de gasto variável do cartão é aproximada | explicitamente rotulada como estimativa e desligável |
