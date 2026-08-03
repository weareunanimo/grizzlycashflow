# Grizzly Cashflow

WebApp responsivo de gestão financeira pessoal, projetado para uso diário e vida longa.

**Objetivo central:** ver o futuro do fluxo de caixa — em especial o que já está comprometido em
parcelas de cartão de crédito — sem digitar nada manualmente. Você joga no sistema o que já existe
(PDF da fatura, CSV do banco, print da notificação, uma frase, um áudio) e ele reconstrói a verdade
financeira, sem duplicar nada.

## Estado atual

🟡 **Fase de arquitetura — nenhuma linha de código de aplicação escrita.**
Aguardando aprovação do product owner nos ADRs 0002, 0003, 0004 e 0005.

## Por onde começar a ler

1. **[PROJECT.md](PROJECT.md)** — documento mestre: princípios, glossário, invariantes, índice de decisões
2. **[docs/adr/README.md](docs/adr/README.md)** — todas as decisões arquiteturais com prós e contras
3. **[docs/01-arquitetura.md](docs/01-arquitetura.md)** — visão geral do sistema

| Doc | Conteúdo |
|-----|----------|
| [01](docs/01-arquitetura.md) | Arquitetura, camadas, módulos, pipeline de ingestão |
| [02](docs/02-modelo-de-dados.md) | Modelagem completa do banco + DDL comentado |
| [03](docs/03-fluxos.md) | Fluxogramas: ingestão, parcelas, dedup, recorrência, projeção |
| [04](docs/04-estrutura-de-pastas.md) | Estrutura de diretórios e deploy |
| [05](docs/05-api.md) | Contrato da API v1 |
| [06](docs/06-ingestao-ia.md) | Estratégias de PDF, CSV, OCR, áudio, texto, IA e custo |
| [07](docs/07-inteligencia.md) | Duplicidade, conciliação, recorrências, regras, aprendizado |
| [08](docs/08-ux.md) | Design system, telas, dark mode, mobile |
| [09](docs/09-roadmap.md) | MVP → V1 → V2 → V3 |
| [10](docs/10-riscos.md) | Riscos, probabilidade, impacto, mitigação |
| [11](docs/11-seguranca.md) | Segurança, autenticação, backup, LGPD |
| [12](docs/12-crescimento.md) | Escala futura + arquitetura do WhatsApp |

## Stack

HTML · TailwindCSS · JavaScript moderno (ES modules) · Chart.js · PHP 8.2+ · MySQL 8 / MariaDB 10.4+ ·
hospedagem PHP compartilhada com cron.

Custo de infraestrutura alvo: hospedagem existente + **~US$ 0,30/mês** de API de IA (teto configurado em US$ 5).
