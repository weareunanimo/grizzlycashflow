# 04 — Estrutura de Pastas e Deploy

> **Nota de atualização (ADR-0005, 2026-08-04):** o framework escolhido foi **Laravel**, não Slim (a
> árvore abaixo refletia a proposta original). Mapeamento para quem já leu a versão anterior:
>
> | Proposta original | Com Laravel |
> |---|---|
> | `src/Kernel/` (bootstrap/DI/router próprios) | `bootstrap/`, `config/`, `app/Providers/` do Laravel |
> | `bin/console` (CLI próprio) | `artisan` + comandos custom em `app/Console/Commands/` |
> | `cron/tick.php` + crontab de 1 linha | **igual na prática**: 1 linha de cron chamando `php artisan schedule:run` a cada minuto; o *scheduler* do Laravel (`app/Console/Kernel.php`) decide o que rodar — mesmo desenho do ADR-0002, sintaxe do Laravel por baixo |
> | `phinx.php` + `database/migrations/` | `database/migrations/` **continua o mesmo caminho** — é a convenção nativa do Laravel; roda com `php artisan migrate` no lugar de `vendor/bin/phinx` |
> | `Http/routes.php` | `routes/api.php` (Laravel) |
>
> **O que não muda:** `Domain/` e `Application/` continuam existindo como PHP puro, isolados do
> framework — só que agora vivem em `src/` com namespace próprio (`Grizzly\Domain`, `Grizzly\Application`),
> registrado via `"Grizzly\\": "src/"` no `composer.json`, **ao lado** do namespace padrão
> `"App\\": "app/"` do Laravel. `app/` fica reservado para a cola fina do framework (Controllers,
> Form Requests, Console Commands, Service Providers) — que só chama os use cases de `src/Application/`,
> nunca contém regra de negócio. Isso preserva o teste de regra de dependência do ADR-0001 sem abrir
> mão da produtividade do Laravel.

## 1. Árvore do repositório

```
grizzlycashflow/
├── PROJECT.md                       # documento mestre (memória do projeto)
├── README.md
├── composer.json / composer.lock
├── package.json                     # só dev: tailwind + esbuild
├── phpunit.xml
├── artisan                          # CLI do Laravel (migrate, queue:work, schedule:run, comandos custom)
├── .env.example                     # nunca commitar .env
├── .editorconfig / .gitignore
│
├── public/                          # ⚠️ ÚNICO diretório exposto na web
│   ├── index.php                    # front controller (10 linhas)
│   ├── .htaccess                    # rewrite + headers de segurança
│   ├── manifest.webmanifest         # PWA
│   ├── sw.js                        # service worker (V1)
│   ├── favicon.svg
│   └── assets/
│       ├── dist/                    # ✅ buildado e versionado (host não tem Node)
│       │   ├── app.[hash].js
│       │   ├── app.[hash].css
│       │   └── manifest.json        # mapa nome→hash
│       └── vendor/chart.umd.min.js
│
├── src/
│   ├── Kernel/                      # bootstrap, DI, roteamento, erros
│   │   ├── App.php
│   │   ├── Container.php            # DI container leve (bindings explícitos)
│   │   ├── Config.php
│   │   ├── Router.php
│   │   └── Bootstrap.php
│   │
│   ├── Support/                     # utilitários puros, zero dependência
│   │   ├── Money.php                # ⭐ VO de centavos: soma, rateio de parcelas, format
│   │   ├── DateRange.php
│   │   ├── Clock.php                # interface — nunca chamar date() direto
│   │   ├── Ulid.php
│   │   ├── Str.php                  # normalize, slug, trigram similarity
│   │   ├── Result.php
│   │   └── Assert.php
│   │
│   ├── Domain/                      # ⭐ REGRA DE NEGÓCIO PURA — zero PDO/HTTP
│   │   ├── Identity/
│   │   │   ├── User.php · Session.php · AuditEntry.php
│   │   │   ├── UserRepository.php            (interface)
│   │   │   └── AuditLogger.php               (interface)
│   │   ├── Ledger/
│   │   │   ├── Transaction.php · Account.php · Institution.php
│   │   │   ├── ValueObject/{Direction,PaymentMethod,TransactionStatus,Fingerprint}.php
│   │   │   ├── TransactionRepository.php · AccountRepository.php
│   │   │   └── Service/{BalanceCalculator,TransferPairing}.php
│   │   ├── Card/
│   │   │   ├── CreditCard.php · Statement.php · CardPurchase.php · Installment.php
│   │   │   ├── Service/
│   │   │   │   ├── InstallmentPatternDetector.php   # ⭐ "05/12" → n,N
│   │   │   │   ├── PurchaseGroupKey.php             # ⭐ chave de reconhecimento
│   │   │   │   ├── InstallmentPlanBuilder.php       # ⭐ reconstrói passado + projeta futuro
│   │   │   │   ├── StatementCycleCalculator.php     # fechamento/vencimento/dia útil
│   │   │   │   └── CommittedAmountCalculator.php
│   │   │   └── {CreditCardRepository,StatementRepository,PurchaseRepository}.php
│   │   ├── Classification/
│   │   │   ├── Category.php · Merchant.php · Counterparty.php · Rule.php
│   │   │   ├── Classifier.php                (interface)
│   │   │   ├── Service/
│   │   │   │   ├── ClassificationCascade.php        # ⭐ orquestra os 5 níveis
│   │   │   │   ├── RuleEngine.php
│   │   │   │   ├── MerchantNormalizer.php
│   │   │   │   ├── NaiveBayesClassifier.php
│   │   │   │   ├── SeedDictionary.php
│   │   │   │   └── RuleSuggestionDetector.php
│   │   │   └── ...Repository.php
│   │   ├── Recurrence/
│   │   │   ├── Recurrence.php · Occurrence.php · PriceChange.php
│   │   │   └── Service/{RecurrenceMatcher,ScheduleCalculator,PatternDetector,PriceChangeTracker}.php
│   │   ├── Ingestion/
│   │   │   ├── Document.php · RawItem.php · CandidateTransaction.php
│   │   │   ├── IngestionChannel.php · Extractor.php · Normalizer.php   (interfaces)
│   │   │   └── Service/{DocumentDeduplicator,ChannelRegistry}.php
│   │   ├── Import/
│   │   │   ├── ImportBatch.php · ImportRow.php · ImporterProfile.php
│   │   │   ├── Importer.php                  (interface)
│   │   │   └── Service/{ColumnMapper,LayoutDetector,BatchValidator}.php
│   │   ├── Reconciliation/
│   │   │   ├── FingerprintBuilder.php · DedupCandidate.php · Decision.php
│   │   │   └── Service/{Reconciler,SimilarityScorer,TransactionMerger}.php
│   │   ├── Projection/
│   │   │   ├── CashEvent.php · CashFlowBucket.php · Forecast.php
│   │   │   └── Service/{CashFlowEngine,VariableSpendEstimator,RiskEvaluator,SnapshotBuilder}.php
│   │   ├── Insight/
│   │   │   ├── Insight.php · Analyzer.php    (interface)
│   │   │   └── Analyzer/{CategorySpike,NegativeForecast,CommittedTotal,SubscriptionAudit,
│   │   │                 TopMerchants,PriceIncrease,MissedPayment,SavingsRate}.php
│   │   └── Ai/                       # contratos, não implementação
│   │       ├── AiProvider.php · TranscriptionProvider.php · VisionProvider.php
│   │       ├── ExtractionRequest.php · Confidence.php
│   │       └── AiBudget.php          # teto de gasto
│   │
│   ├── Application/                 # 1 arquivo = 1 caso de uso
│   │   ├── UseCase/
│   │   │   ├── Transaction/{Create,Update,Delete,BulkCategorize,Merge,Split}.php
│   │   │   ├── Document/{Upload,Unlock,Reprocess,Discard}.php
│   │   │   ├── Import/{PreviewBatch,CommitBatch,RollbackBatch,SaveProfile}.php
│   │   │   ├── Card/{ImportStatement,PayStatement,ListCommitted,ForecastStatements}.php
│   │   │   ├── Recurrence/{Create,Update,Cancel,AcceptSuggestion,ListHistory}.php
│   │   │   ├── Rule/{Create,Update,Reorder,AcceptSuggestion,ApplyRetroactively}.php
│   │   │   ├── Quick/{CaptureText,CaptureAudio,CaptureImage}.php
│   │   │   ├── Dashboard/{GetOverview,GetCashFlow,GetCategoryBreakdown,GetCardPanel}.php
│   │   │   └── Insight/{Generate,Dismiss}.php
│   │   ├── Dto/                     # objetos de entrada/saída dos use cases
│   │   ├── Job/                     # handlers da fila
│   │   │   ├── ParseDocumentJob.php · ClassifyBatchJob.php · RebuildProjectionJob.php
│   │   │   ├── SweepRecurrencesJob.php · GenerateInsightsJob.php · RunBackupJob.php
│   │   │   └── JobHandler.php       (interface)
│   │   └── Event/                   # eventos internos + listeners (invalidar snapshot etc.)
│   │
│   ├── Infrastructure/
│   │   ├── Persistence/Mysql/
│   │   │   ├── Connection.php · TransactionManager.php
│   │   │   ├── Repository/…Repository.php      # SQL explícito e legível
│   │   │   └── ReadModel/{CashFlowQuery,DashboardQuery,CardPanelQuery}.php
│   │   ├── Queue/{MysqlQueue,JobDispatcher,QueueWorker,Watchdog}.php
│   │   ├── Storage/{LocalFileStorage,FileStorage.php(interface)}.php
│   │   ├── Pdf/
│   │   │   ├── PdfTextExtractor.php           # smalot/pdfparser
│   │   │   ├── PdfDecryptor.php
│   │   │   ├── LineLayoutBuilder.php          # texto + coordenadas → linhas
│   │   │   └── Template/                      # ⭐ 1 arquivo por emissor
│   │   │       ├── IssuerTemplate.php (interface) · TemplateRegistry.php
│   │   │       ├── NubankStatement.php · ItauStatement.php · BradescoStatement.php
│   │   │       ├── SantanderStatement.php · CaixaStatement.php · BbStatement.php
│   │   │       ├── InterStatement.php · C6Statement.php
│   │   │       └── GenericHeuristicStatement.php
│   │   ├── Csv/
│   │   │   ├── CsvReader.php (autodetecção de encoding e delimitador)
│   │   │   └── Importer/{GenericCsvImporter,OfxImporter,NubankCsv,ItauCsv,...}.php
│   │   ├── Ai/
│   │   │   ├── Provider/{ClaudeProvider,OpenAiProvider,FakeProvider,NullProvider}.php
│   │   │   ├── Transcription/{WhisperApiProvider,BrowserProvidedTranscript}.php
│   │   │   ├── Prompt/                        # ⭐ prompts versionados como arquivos
│   │   │   │   ├── statement_extract.v1.txt · receipt_vision.v1.txt
│   │   │   │   ├── text_parse.v1.txt · categorize_batch.v1.txt · insight.v1.txt
│   │   │   ├── ResponseValidator.php          # valida JSON contra schema
│   │   │   ├── AiCallLogger.php · ResponseCache.php · BudgetGuard.php
│   │   │   └── PiiRedactor.php                # se ADR-0003 opção B
│   │   ├── Nlu/{TextTransactionParser,BrazilianAmountParser,RelativeDateParser}.php
│   │   ├── Security/{Argon2Hasher,TotpVerifier,Encryptor,RateLimiter,CsrfGuard}.php
│   │   ├── Mail/{SmtpMailer,Mailer.php(interface)}.php
│   │   ├── Backup/{MysqlDumper,BackupArchiver,OffsiteUploader}.php
│   │   └── Log/{JsonFileLogger,RequestContext}.php
│   │
│   └── Http/
│       ├── Middleware/{ErrorHandler,RequestId,Authenticate,RequireTotp,CsrfProtect,
│       │               RateLimit,JsonBody,SecurityHeaders,AuditContext}.php
│       ├── Controller/
│       │   ├── Api/V1/{Auth,Transactions,Accounts,Cards,Statements,Purchases,
│       │   │           Categories,Merchants,Rules,Recurrences,Documents,Imports,
│       │   │           Dashboard,CashFlow,Insights,Quick,Search,Export,Settings,Health}Controller.php
│       │   ├── Web/{AppShellController,AttachmentController}.php
│       │   └── Webhook/WhatsAppController.php        # V3
│       ├── Request/                 # validação declarativa por endpoint
│       ├── Response/{JsonResponse,Problem.php}      # erros em RFC 7807
│       └── routes.php               # ⭐ todas as rotas em um arquivo legível
│
├── resources/
│   ├── views/{app.php,login.php,error.php}          # shell mínimo server-rendered
│   ├── emails/{alert.php,backup_failed.php,reset_password.php}
│   └── seeds/{categories.json,rules_br.json,institutions_br.json}
│
├── frontend/                        # fonte do front (compilado para public/assets/dist)
│   ├── src/
│   │   ├── main.js
│   │   ├── core/{router.js,api.js,store.js,formatters.js,toast.js,modal.js}
│   │   ├── components/{AppShell,NavBar,BottomNav,TransactionList,TransactionRow,
│   │   │               TransactionSheet,QuickCapture,VoiceRecorder,ImportPreview,
│   │   │               DedupDialog,ConfidenceBadge,CardPanel,InstallmentTimeline,
│   │   │               RecurrenceCard,RuleBuilder,InsightFeed,DateRangePicker,
│   │   │               MoneyInput,CategoryPicker,EmptyState,Skeleton}/
│   │   ├── charts/{cashflow.js,categoryDonut.js,monthlyBars.js,heatmap.js,
│   │   │           installmentStack.js,chartTheme.js}
│   │   ├── pages/{dashboard,transactions,cards,cashflow,recurrences,rules,
│   │   │          import,insights,settings,review}.js
│   │   └── styles/{app.css,tokens.css}
│   ├── tailwind.config.js
│   └── build.mjs                    # esbuild
│
├── database/
│   ├── migrations/                  # Laravel, uma por mudança
│   └── seeds/
│
├── storage/                         # ⚠️ FORA do webroot · não versionado
│   ├── uploads/{yyyy}/{mm}/         # documentos originais
│   ├── logs/ · cache/ · backups/ · tmp/
│
├── app/Console/Commands/             # comandos artisan custom:
│                                    #   queue:work, backup:run, reprocess:documents,
│                                    #   reindex:classifier, export:all, user:create
├── app/Console/Kernel.php            # scheduler — decide o que roda a cada minuto de cron
│
├── tests/
│   ├── Unit/                        # domínio puro — rápido, sem banco
│   │   ├── Card/InstallmentPlanBuilderTest.php      # ⭐ o teste mais importante do projeto
│   │   ├── Card/PurchaseGroupKeyTest.php
│   │   ├── Reconciliation/SimilarityScorerTest.php
│   │   ├── Projection/CashFlowEngineTest.php
│   │   ├── Support/MoneyTest.php
│   │   └── ...
│   ├── Integration/                 # com MySQL de teste
│   ├── Feature/                     # HTTP ponta a ponta
│   ├── Invariants/                  # ⭐ os 10 invariantes do PROJECT.md como testes
│   ├── Architecture/DependencyRuleTest.php
│   └── Fixtures/{statements/,csv/,images/,audio/}   # amostras anonimizadas reais
│
└── docs/                            # esta documentação
```

## 2. Deploy em hospedagem compartilhada

O layout ideal (DocumentRoot apontando para `public/`):

```
/home/usuario/
├── app/                 ← o repositório (git pull ou upload)
│   ├── src/ storage/ vendor/ .env ...
└── public_html/         ← DocumentRoot
    └── (conteúdo de app/public/ · symlink se o host permitir)
```

**Se o host não permitir mudar o DocumentRoot nem criar symlink** (cenário comum):
`public_html/index.php` vira um stub de 3 linhas que dá `require` no bootstrap em `../app/`,
e um `.htaccess` na raiz bloqueia acesso a qualquer coisa que não seja `index.php` e `assets/`.
Documentado em `docs/deploy.md` na fase de implementação. **Nunca** deixar `src/`, `storage/`,
`vendor/` ou `.env` acessíveis por HTTP.

### `public/.htaccess` (essência)

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [QSA,L]

Options -Indexes
<FilesMatch "\.(env|md|json|lock|sql|log|ini)$">
  Require all denied
</FilesMatch>

Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "DENY"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set Content-Security-Policy "default-src 'self'; img-src 'self' data: blob:; script-src 'self'; style-src 'self'; connect-src 'self'; media-src 'self' blob:; frame-ancestors 'none'; base-uri 'none'"
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
```

### Processo de release

```bash
# local
npm run build                       # tailwind + esbuild → public/assets/dist
composer install --no-dev -o        # autoloader otimizado
php artisan test                    # suite completa; falha = não sobe
git push origin <branch>

# no servidor (SSH ou script deploy.php protegido por token)
git pull --ff-only
php artisan migrate                 # migrações
php artisan config:cache && php artisan route:cache
```

- **Sem SSH?** Rota `POST /admin/deploy` protegida por token no `.env` que faz pull + migrate,
  ou upload por FTP + rota `POST /admin/migrate`. Documentado, com log e auditoria.
- **Rollback:** `git checkout <tag anterior>` + `php artisan migrate:rollback`. Toda release
  ganha uma tag e um dump do banco antes de migrar.

### Crontab (única linha necessária)

```cron
* * * * * /usr/bin/php /home/usuario/app/artisan schedule:run >> /home/usuario/app/storage/logs/cron.log 2>&1
```

O *scheduler* do Laravel (`app/Console/Kernel.php`) decide internamente o que rodar a cada minuto
(ver fluxo 10 em `03-fluxos.md`) — mesmo desenho do ADR-0002 (fila em MySQL, claim atômico, watchdog),
só que agendado pela sintaxe nativa do Laravel em vez de um script próprio. Uma linha só de cron
mantém o sistema inteiro vivo — importante porque muitos hosts limitam o número de tarefas.

## 3. Dependências Composer (ADR-0005: Laravel como base)

| Pacote | Para quê | Por que este |
|--------|----------|--------------|
| `laravel/laravel` | framework: roteamento, sessão, validação, Query Builder, filas, e-mail, migrações | produtividade desde o dia 1; confinado a `Http/`/`Infrastructure/` (ver nota de arquitetura acima) |
| `robthree/twofactorauth` | TOTP | 2FA — mais leve que Fortify/Jetstream para o que precisamos |
| `smalot/pdfparser` | texto de PDF (V1) | PHP puro, roda em qualquer host |
| `phpunit/phpunit` (dev) | testes | vem com o Laravel, mantido |
| `phpstan/phpstan` (dev) | análise estática nível 8 (com `larastan/larastan`) | pega bug antes do runtime, inclusive dentro de código Laravel |

**Deliberadamente ausentes/evitados:** Eloquent **como entidade de domínio** (Query Builder sim,
Eloquent-como-Model não atravessa para `Domain/`), Fortify/Jetstream/Breeze (autenticação escrita à
mão nos moldes do ADR-0009 — sessão server-side, Argon2id, TOTP — mais simples e mais alinhada ao
projeto do que o pacote de scaffolding completo), fila externa (Redis/SQS) e cliente HTTP pesado além
do que o Laravel já traz. Cada dependência extra é um passivo de 10 anos — a escolha de Laravel troca
várias dependências pequenas por uma só, mas bem confinada (ver ADR-0005).

## 4. Padrões de código

- `declare(strict_types=1)` em todo arquivo. PHPStan nível 8, `mixed` proibido em assinatura pública.
- PSR-12, PSR-4 (`Grizzly\`), PSR-7/15/11 nas fronteiras.
- Entidades de domínio imutáveis onde faz sentido; mutação via métodos com nome de negócio
  (`$purchase->markInstallmentBilled(5, $statement)`), nunca setters anêmicos.
- Nenhum `date()`/`time()` direto no domínio — sempre `Clock` injetado (torna teste de projeção determinístico).
- Nenhum `float` em código financeiro. `Money` proíbe construção a partir de float.
- SQL sempre com prepared statements nomeados. Zero concatenação de input.
- Nome de teste descreve regra de negócio: `test_reimporting_statement_does_not_duplicate_purchase()`.
