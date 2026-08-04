# Grizzly Cashflow — Documento Mestre do Projeto

> **Este arquivo é a memória de longo prazo do projeto.**
> Toda decisão arquitetural relevante é registrada aqui (ou em `docs/adr/`).
> Antes de implementar qualquer coisa, leia este arquivo. Ao decidir algo novo,
> registre aqui **no mesmo commit** da implementação.

- **Versão do documento:** 0.2 (arquitetura calibrada com dados reais — nenhum código de aplicação escrito)
- **Última atualização:** 2026-08-04
- **Status:** 🟡 Arquitetura entregue e validada contra CSVs reais do Banco XP — aguardando aprovação do product owner
- **Branch de desenvolvimento:** `claude/personal-finance-architecture-4ub3qs`

---

## 1. O que é este produto

WebApp responsivo de **gestão financeira pessoal de uso diário e vitalício**, para
um único usuário principal (o dono do projeto), com o objetivo central de:

> **Ver o futuro do fluxo de caixa** — em especial o que já está comprometido em
> parcelas de cartão de crédito — sem digitar nada manualmente.

A entrada de dados é **passiva e multicanal**: o usuário joga no sistema o que já
existe (PDF da fatura, CSV do banco, print da notificação, uma frase, um áudio) e
o sistema reconstrói a verdade financeira, sem duplicar nada.

### Os 5 princípios de produto (não negociáveis)

| # | Princípio | Consequência prática |
|---|-----------|----------------------|
| P1 | **Nunca duplicar** | Toda ingestão passa por conciliação antes de gravar. Em dúvida, pergunta. |
| P2 | **Zero digitação manual como meta** | 95%+ de classificação automática após alguns meses. Toda correção do usuário vira aprendizado. |
| P3 | **O futuro é o produto** | Projeção não é um relatório: é a tela principal. Parcelas futuras são cidadãos de primeira classe do modelo de dados. |
| P4 | **A IA nunca decide sozinha em baixa confiança** | Todo output de IA carrega `confidence` 0–100%. Abaixo do limiar → fila de revisão, nunca gravação silenciosa. |
| P5 | **Dado do usuário é sagrado** | Nada é apagado de verdade (soft delete + audit log). Exportação completa sempre disponível. Backup testado. |

### Os 3 princípios de engenharia

| # | Princípio | Consequência prática |
|---|-----------|----------------------|
| E1 | **Monólito modular, não bagunça** | Domínio puro em PHP sem dependência de framework/banco. Infra é plugin. |
| E2 | **Tudo que é externo é uma interface** | Provedor de IA, transcrição, storage, fila e importadores são substituíveis sem tocar no domínio. |
| E3 | **Roda em hospedagem compartilhada barata** | Sem daemon, sem Redis, sem worker container. Fila em MySQL, cron de 1 minuto, assets pré-buildados. |

---

## 2. Stack aprovada

| Camada | Escolha | Observação |
|--------|---------|------------|
| Frontend | HTML + TailwindCSS + JS moderno (ES modules) + Chart.js | Build local (Node só em dev), assets compilados versionados |
| Backend | PHP 8.2+ | Monólito modular, API-first |
| Banco | MySQL 8.0+ / MariaDB 10.4+ | InnoDB, utf8mb4. Sem window functions/CTE (portabilidade) |
| Hospedagem | PHP compartilhado + cron | DocumentRoot aponta para `public/` |
| IA | API externa (ver ADR-0003) | Único custo recorrente do projeto |

**Nada de tecnologia caro.** Custo alvo de infra: hospedagem existente + **US$ 2–8/mês** de API de IA.

---

## 3. Índice da documentação

| Doc | Conteúdo |
|-----|----------|
| [docs/01-arquitetura.md](docs/01-arquitetura.md) | Visão geral, camadas, módulos, regras de dependência |
| [docs/02-modelo-de-dados.md](docs/02-modelo-de-dados.md) | Modelagem completa + DDL comentado |
| [docs/03-fluxos.md](docs/03-fluxos.md) | Fluxogramas (ingestão, parcelas, dedup, recorrência, projeção) |
| [docs/04-estrutura-de-pastas.md](docs/04-estrutura-de-pastas.md) | Árvore de diretórios e deploy em hospedagem compartilhada |
| [docs/05-api.md](docs/05-api.md) | Contrato da API v1 completo |
| [docs/06-ingestao-ia.md](docs/06-ingestao-ia.md) | Estratégias de PDF, OCR, áudio, texto, IA e custo |
| [docs/07-inteligencia.md](docs/07-inteligencia.md) | Dedup/conciliação, recorrências, regras, aprendizado, insights |
| [docs/08-ux.md](docs/08-ux.md) | Design system, telas, dark mode, mobile |
| [docs/09-roadmap.md](docs/09-roadmap.md) | MVP → V1 → V2 → V3 |
| [docs/10-riscos.md](docs/10-riscos.md) | Riscos, probabilidade, impacto, mitigação |
| [docs/11-seguranca.md](docs/11-seguranca.md) | Segurança, autenticação, backup, LGPD |
| [docs/12-crescimento.md](docs/12-crescimento.md) | Escala futura + arquitetura do WhatsApp |
| [docs/13-perfis-importadores-xp.md](docs/13-perfis-importadores-xp.md) | ⭐ Perfis do Banco XP e do Visa Black, derivados dos arquivos reais |
| [docs/adr/README.md](docs/adr/README.md) | **Todos os ADRs (decisões arquiteturais)** |

---

## 4. Registro de decisões (ADR index)

Status: 🟢 aprovada · 🟡 proposta (aguarda aprovação) · 🔵 aceita por padrão (baixo risco) · ⚪ futura · 🔴 rejeitada

| ADR | Decisão | Status |
|-----|---------|--------|
| [0001](docs/adr/README.md#adr-0001) | Monólito modular com domínio isolado (Clean Architecture pragmática) | 🔵 |
| [0002](docs/adr/README.md#adr-0002) | Fila de trabalhos em MySQL drenada por cron (sem daemon) | 🟡 **precisa aprovação** |
| [0003](docs/adr/README.md#adr-0003) | Postura de privacidade e provedor de IA | 🟡 **precisa aprovação** |
| [0004](docs/adr/README.md#adr-0004) | Compra parcelada como entidade de primeira classe (`card_purchases` + `card_installments`) | 🟡 **precisa aprovação** |
| [0005](docs/adr/README.md#adr-0005) | Framework: Slim 4 + libs Composer enxutas | 🟡 **precisa aprovação** |
| [0006](docs/adr/README.md#adr-0006) | Dinheiro sempre em centavos inteiros (`BIGINT`) | 🔵 |
| [0007](docs/adr/README.md#adr-0007) | Dois eixos temporais: competência vs. caixa; cartão agrega em evento único de fatura | 🔵 |
| [0008](docs/adr/README.md#adr-0008) | Multi-tenant desde o dia 1 (`user_id` em tudo), UX single-user | 🔵 |
| [0009](docs/adr/README.md#adr-0009) | Autenticação: sessão server-side + Argon2id + TOTP; sem cadastro público | 🔵 |
| [0010](docs/adr/README.md#adr-0010) | Recorrência é agrupador, nunca gerador de lançamento | 🔵 (requisito do PO) |
| [0011](docs/adr/README.md#adr-0011) | Frontend: SPA leve em ES modules, sem framework grande | 🔵 |
| [0012](docs/adr/README.md#adr-0012) | Cascata de categorização barata→caro (IA é último recurso) | 🔵 |
| [0013](docs/adr/README.md#adr-0013) | Soft delete + audit log imutável em tudo | 🔵 |
| [0014](docs/adr/README.md#adr-0014) | Importadores como plugins com perfil de layout em banco | 🔵 |
| [0015](docs/adr/README.md#adr-0015) | Anexos fora do webroot, servidos por PHP com autorização | 🔵 |
| [0016](docs/adr/README.md#adr-0016) | Snapshots materializados para performance de gráficos | 🔵 |
| [0017](docs/adr/README.md#adr-0017) | WhatsApp como canal de ingestão, não como módulo novo | ⚪ V3 |
| [0018](docs/adr/0018-fatura-aberta-e-importacao-incremental.md) | Fatura aberta e importação incremental idempotente | 🔵 |
| [0019](docs/adr/0019-validacao-de-integridade-por-cadeia-de-saldo.md) | Validação por cadeia de saldo + conferência cruzada entre canais | 🔵 |
| [0020](docs/adr/0020-consolidacao-de-lancamentos-de-ruido.md) | Consolidação mensal de lançamentos de ruído (rendimento automático) em linha expansível | 🔵 (aprovado pelo PO) |
| [0021](docs/adr/0021-deteccao-de-recorrencia-por-cluster-de-valor.md) | Detecção de recorrência por favorecido **e** cluster de valor | 🔵 |
| [0022](docs/adr/0022-antecipacao-de-fatura.md) | Antecipação de fatura reconhecida como classe própria, não duplicidade | 🔵 (confirmado pelo PO) |

---

## 5. Glossário do domínio (vocabulário único do projeto)

Usar **exatamente** estes termos em código, banco, API e UI. Ambiguidade de vocabulário é a principal causa de apodrecimento de software financeiro.

| Termo | Significado preciso |
|-------|---------------------|
| **Transaction** (movimentação) | Fato financeiro **único e realizado** (ou pendente confirmado). Nunca uma projeção. |
| **Projection** (projeção) | Evento de caixa **futuro e calculado**. Vive em memória/snapshot, nunca em `transactions`. |
| **Account** (conta) | Onde o dinheiro está ou é devido: conta corrente, dinheiro, investimento, **cartão de crédito**. |
| **Credit Card** | Uma `account` do tipo `credit_card` + metadados (limite, fechamento, vencimento). |
| **Statement** (fatura) | Ciclo de um cartão: mês de referência, fechamento, vencimento, total. |
| **Card Purchase** (compra) | Uma compra no cartão, à vista (1x) ou parcelada (Nx). É a "obrigação-mãe". |
| **Installment** (parcela) | Uma das N cobranças de uma `card_purchase`, ligada a um mês de referência. |
| **Recurrence** (recorrência) | Padrão de pagamento repetido. **Agrupador**, não gerador (ADR-0010). |
| **Occurrence** | Uma instância esperada de uma recorrência num período; pode estar `matched`, `missed` ou `late`. |
| **Rule** (regra) | Condição→ação determinística de classificação, com prioridade, editável. |
| **Document** | Arquivo bruto ingerido (PDF/CSV/imagem/áudio) — evidência imutável. |
| **Evidence** | Vínculo entre uma `transaction` e um `document`/canal que a comprovou. Uma transação pode ter várias. |
| **Import Batch** | Uma tentativa de importação de um `document`, com preview e commit atômico. |
| **Fingerprint** | Hash determinístico usado para detectar duplicidade. |
| **Merchant** (estabelecimento) | Entidade canônica de um lugar de gasto ("Shell", "Angeloni"), com nome normalizado. |
| **Counterparty** (favorecido) | Pessoa/empresa em PIX/TED ("João"). |
| **Cash Effect Date** | Data em que o dinheiro realmente sai/entra da conta (para cartão = vencimento da fatura). |

---

## 6. Invariantes do sistema (nunca podem ser violadas)

Estas viram testes automatizados no MVP:

1. **I1** — Duas `transactions` não podem compartilhar `(user_id, external_id)` quando `external_id` não é nulo.
2. **I2** — Uma `import_row` só gera no máximo **uma** `transaction`.
3. **I3** — `sum(card_installments.amount_cents)` de uma `card_purchase` = `total_amount_cents` (± N centavos de arredondamento, N = nº de parcelas).
4. **I4** — Um lançamento em conta de cartão **nunca** entra no fluxo de caixa individualmente; só o pagamento/obrigação da fatura entra (ADR-0007).
5. **I5** — Marcar algo como recorrente **nunca** cria `transactions` (ADR-0010).
6. **I6** — Nenhuma `transaction` é gravada com `confidence < threshold` sem `needs_review = 1`.
7. **I7** — Toda mutação de `transaction` gera linha em `audit_log`.
8. **I8** — Nenhum `document` é acessível por URL pública direta.
9. **I9** — Deletar nunca é físico: `deleted_at`, e as `evidences` permanecem.
10. **I10** — Uma `card_purchase` reimportada de outra fatura é **reconhecida**, nunca recriada (via `group_key`).
11. **I11** — Importar o mesmo arquivo N vezes produz exatamente o mesmo estado final (ADR-0018).
12. **I12** — Reimportar uma fatura aberta nunca sobrescreve categoria definida pelo usuário (ADR-0018).
13. **I13** — Importação com `has_running_balance` e cadeia de saldo rompida nunca é commitada (ADR-0019).
14. **I14** — A soma das transações importadas é igual à soma das linhas do arquivo, mesmo com consolidação (ADR-0020).
15. **I15** — Um segundo pagamento de fatura no mesmo ciclo nunca entra no scoring de duplicidade comum; é reconhecido como antecipação (ADR-0022).

---

## 7. Como trabalhar neste repositório

1. Leia `PROJECT.md` (este arquivo) e o ADR relevante.
2. Nada de código de domínio dependendo de PDO, HTTP ou de provedor de IA.
3. Toda feature nova: teste primeiro nas invariantes que ela toca.
4. Decisão nova ou mudança de decisão → novo ADR em `docs/adr/` + atualizar a tabela da seção 4.
5. Nunca commitar `.env`, `storage/`, dumps ou credenciais.

---

## 8. Pendências abertas (aguardando o PO)

- [ ] Aprovar ADR-0002 (fila via cron)
- [ ] Aprovar ADR-0003 (postura de privacidade / IA)
- [ ] Aprovar ADR-0004 (modelo de parcelas)
- [ ] Aprovar ADR-0005 (framework)
- [ ] Confirmar dia de fechamento e vencimento do Visa Black XP (inferido: fecha ~17/18, vence dia 01)
- [ ] Confirmar limite do cartão (para o cálculo de limite livre)
- [ ] Liberar acesso de escrita ao repositório para esta sessão (push está retornando 403)

### ✅ Resolvidas em 2026-08-04

- [x] Instituições: **Banco XP** (conta) + **Visa Black XP** (cartão)
- [x] Faturas **não** têm senha → `PdfDecryptor` sai do caminho crítico do MVP
- [x] Amostras reais recebidas: extrato (110 lançamentos) e fatura (110 itens) → perfis de importador
      especificados em `docs/13-perfis-importadores-xp.md`, ADRs 0018–0021 derivados dos dados
- [x] ADR-0020 aprovado: rendimento automático consolidado em 1 linha por mês, expansível ao clicar
- [x] Pagamentos duplos de fatura = antecipação, confirmado pelo PO (ADR-0022, não é duplicidade)
