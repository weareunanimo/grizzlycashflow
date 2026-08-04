# 09 — Roadmap

Estimativas em semanas de trabalho focado. A ordem é deliberada: **cada fase entrega algo utilizável
de verdade**, e nada é construído antes da coisa que o valida.

---

## Fase 0 — Fundação (1 semana)

Não entrega valor ao usuário, mas define o custo de todas as fases seguintes.

- [ ] Estrutura de pastas, `composer.json`, autoload PSR-4, PHPStan nível 8, PHPUnit
- [ ] Kernel: container DI, router, middlewares (erro, request-id, CSRF, rate limit), `.env`
- [ ] `Money`, `Clock`, `Str::normalize`, trigram similarity, `Ulid` — **com testes**
- [ ] Phinx + migrações do schema completo (doc 02) + seeds (categorias, ~60 regras BR)
- [ ] Fila MySQL + `cron/tick.php` + watchdog + `bin/console`
- [ ] Auth: login, sessão server-side, Argon2id, lockout, `bin/console user:create`
- [ ] Build do front (Tailwind + esbuild), shell da SPA, router client-side, cliente de API
- [ ] CI: PHPStan + PHPUnit + teste de regra de dependência arquitetural
- [ ] Deploy manual funcionando na hospedagem real **desde o dia 1** (nada pior que descobrir no fim)

**Aceite:** login funciona em produção, `/admin/health` responde, cron drena job de teste, suíte verde.

---

## MVP — "Ver o futuro do meu cartão" (4–5 semanas)

> Alvo: o PO joga um PDF de fatura no sistema e vê, sem digitar nada, quanto está comprometido nos
> próximos meses. **Se só isso funcionar, o produto já vale.**

### Semana 1–2 — Ledger + Cartões
- [ ] Contas, instituições, cartões (limite, fechamento, vencimento, dia útil)
- [ ] CRUD de movimentações + categorias em 2 níveis + tags + anexos
- [ ] `card_purchases` + `card_installments` + **`InstallmentPlanBuilder`** (o teste mais importante)
- [ ] `StatementCycleCalculator` (feriados nacionais + dia útil)
- [ ] Cálculo de comprometido e limite disponível

### Semana 2–3 — Importação de CSV (Banco XP + Visa Black XP)
> **Revisado em 2026-08-04 após receber os arquivos reais.** O CSV da fatura XP já contém tudo que o
> objetivo nº 1 precisa (data da compra, estabelecimento, valor, `N de M`). O parser de PDF saiu do
> MVP e foi para V1 — spec completa em `docs/13-perfis-importadores-xp.md`.

- [ ] Pipeline de ingestão completo (Document → job → extract → normalize → preview → commit)
- [ ] Perfil `xp_cartao_fatura_csv` + perfil `xp_conta_csv`
- [ ] `InstallmentPatternDetector` (`N de M`) + `PurchaseGroupKey` + reconstrução/projeção
- [ ] **Validação por cadeia de saldo** no extrato (ADR-0019) — bloqueia commit se romper
- [ ] **Importação incremental de fatura aberta** (ADR-0018) — reimportação semanal idempotente
- [ ] `MerchantNormalizer` com a lista real de prefixos de gateway (MP*, IFD*, SHOPEE*, …)
- [ ] Consolidação de rendimento automático (ADR-0020, se aprovado)
- [ ] Pareamento `PAGAMENTO DE FATURA` (extrato) ↔ fatura via `transfer_group_id`
- [ ] Casos de regressão dos dados reais: colisões `MP *DIGITALIMPORT` e `MP*MERCADOLIVRE`,
      parcela `de 1`, estornos negativos, `YELUMSEG PARC8`

### Semana 3–4 — Conciliação + Classificação
- [ ] `FingerprintBuilder`, `SimilarityScorer`, `Reconciler`, `TransactionMerger`
- [ ] Fila de decisão de duplicidade + UI substituir/ignorar/manter ambas
- [ ] Cascata de categorização (regra → memória → semente → Bayes → LLM) + confiança
- [ ] Aprendizado: `category_feedback`, `merchant_category_stats`, `classifier_tokens`
- [ ] `audit_log` em toda mutação + tela de histórico

### Semana 4–5 — Projeção + UI
- [ ] `CashFlowEngine` + read models + rebuild por cron
- [ ] Tela **Cartões** completa (fatura atual, próximas 12, parcelas, calendário)
- [ ] Tela **Hoje** + **Movimentações** + **Importação/preview** + **Revisão**
- [ ] Gráficos: fluxo de caixa, gastos por categoria, próximas faturas
- [ ] Dark mode, responsivo, testado em celular real
- [ ] Backup automático + restauração testada **antes de considerar MVP pronto**

**Critérios de aceite do MVP (todos verificáveis):**
1. Importar 3 faturas reais consecutivas → parcelas corretas, **zero duplicata**
2. Ver comprometimento correto dos próximos 12 meses
3. Reimportar a mesma fatura → 0 criações, 0 duplicatas
4. ≥ 85% de classificação automática nas faturas de teste
5. Funcionar bem no celular do PO
6. Backup gerado, baixado e **restaurado com sucesso**

---

## V1 — "Centralizar tudo" (4–5 semanas)

- [ ] **Importação de PDF de fatura** (movida do MVP): `PdfTextExtractor`, `LineLayoutBuilder`,
  `GenericHeuristicStatement`, template do XP, fallback de extração por LLM
- [ ] **CSV genérico**: detecção de layout, mapeamento assistido pelo usuário, perfis salvos
- [ ] **Captura por texto** (parser BR + LLM) com confirmação por chips
- [ ] **Captura por imagem** (visão) — prints de PIX/boleto/TED/cartão
- [ ] **Captura por voz** (Web Speech + fallback de transcrição no servidor)
- [ ] **Recorrências completas**: cadastro, casamento, ocorrências, atraso, reajustes, métricas, sugestões
- [ ] **Regras completas**: builder visual, prioridade arrastável, dry-run, aplicação retroativa, sugestões
- [ ] **Dashboards completos**: comparativo, heatmap, calendário, top estabelecimentos, patrimônio
- [ ] **Insights**: 9 analyzers determinísticos + redação por LLM
- [ ] Transferências entre contas (pareamento) e pagamento de fatura conciliado
- [ ] Split de transação, merge manual, exportação CSV/JSON completa
- [ ] 2FA (TOTP), gestão de sessões/dispositivos
- [ ] PWA instalável + offline básico
- [ ] Alertas por e-mail (fatura fechando, boleto vencendo, mês crítico, backup/cron falhou)

**Aceite:** todos os canais de entrada do PO funcionando; ≥ 92% de classificação automática; nenhuma
duplicata em 3 meses de uso real.

---

## V2 — "Planejar e otimizar" (4–6 semanas)

- [ ] **Orçamentos** por categoria com acompanhamento e alerta de estouro
- [ ] **Metas** de economia com progresso e projeção de alcance
- [ ] **Simulador de cenários** ("e se eu parcelar X?", "e se cortar Y?")
- [ ] **Auditoria de assinaturas**: detecta não usadas, sobrepostas, aumentos, sugere cancelamento
- [ ] Contas compartilhadas / divisão de despesas (quem deve o quê)
- [ ] Investimentos: aportes, saldo manual, rendimento, patrimônio consolidado
- [ ] Relatório mensal em PDF (visão executiva)
- [ ] Previsão sazonal (IPVA, IPTU, material escolar, 13º) a partir do histórico
- [ ] Reprocessamento retroativo com parsers atualizados
- [ ] Anotações e comprovantes fiscais organizados para IR

---

## V3 — "Onipresente" (5–8 semanas)

- [ ] **WhatsApp** (arquitetura em `docs/12-crescimento.md`): encaminhar PDF/imagem/áudio/texto
  para um número e cair no sistema; confirmação com botões; consultas ("quanto gastei este mês?")
- [ ] Multiusuário real (família) com permissões e visão consolidada
- [ ] API pública com tokens escopados + webhooks
- [ ] Multi-moeda com cotação histórica
- [ ] Integração com e-mail (encaminhar fatura para um endereço dedicado)
- [ ] Open Finance — **só se, até então, os canais atuais provarem-se insuficientes**
- [ ] Assistente conversacional sobre os próprios dados ("quanto gastei com pet em 2027?")

---

## Como cada fase termina

Nenhuma fase é "concluída" sem:

1. Testes das invariantes que ela toca (verdes)
2. `PROJECT.md` atualizado com as decisões tomadas
3. Deploy em produção + uso real por ≥ 1 semana
4. Backup restaurado com sucesso após a fase
5. Métricas de `/admin/health` dentro do esperado

**Fora de escopo permanente:** virar SaaS multi-tenant comercial, app nativo, integração com corretoras,
emissão fiscal. Se algum desses entrar, é um produto novo e uma conversa nova.
