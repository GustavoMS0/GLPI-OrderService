# Order Service para GLPI

> [!WARNING]
> **Status: Em Desenvolvimento (Beta / Pré-homologação)**
> Este plugin teve a arquitetura, a segurança e os fluxos validados por revisão de código, auditoria de segurança e testes unitários automatizados em CI (`PHP 7.4–8.3` com mocks de GLPI 10/11/12), mas **ainda não foi testado em uma instância GLPI ao vivo**. Valide em ambiente de homologação antes de usar em produção e reporte eventuais ajustes via Issues.

Gestão de Ordens de Serviço digitais vinculadas a chamados (`Ticket`) com itens de serviço/material/deslocamento, assinatura em `<canvas>` PNG validada, assinatura remota em duas etapas e impressão autorizada para **GLPI 10, 11 e 12**.

## Funcionalidades

- **Ordens de Serviço Sequenciais (`OS-YYYY-000001`):**
  - Criação direta na aba **Ordens de Serviço** dentro do chamado (`Ticket`).
  - Controle completo de ciclo de vida (`draft`, `pending_signature`, `signed`, `cancelled`).
  - Limpeza automática de ordens e itens órfãos quando um chamado é expurgado (`item_purge`).
- **Itens, Materiais e Deslocamento com Precisão em Centavos:**
  - Adição e remoção de itens classificados por tipo (`service`, `material`, `travel`) com quantidade fixa em 2 casas decimais e valor unitário em centavos inteiros.
  - Taxa de deslocamento configurável por padrão na administração ou ajustável por ordem.
- **Assinatura Digital PNG Validada (`<canvas>`):**
  - Captura presencial (técnico e cliente) diretamente na aba do chamado em JavaScript puro, sem bibliotecas externas e sem uso de `innerHTML` ou `eval`.
  - Validação estrita no servidor: exige prefixo `data:image/png;base64,`, decodificação estrita, verificação dos bytes mágicos PNG (`\x89PNG\r\n\x1a\n` + chunk `IHDR`), limite máximo de 256 KB e re-codificação canônica.
- **Assinatura Remota em Duas Etapas (`front/sign.php`):**
  - Geração de token criptográfico efêmero (armazenando apenas o hash `SHA-256` no banco e exibindo o link uma única vez na sessão).
  - Requisição `GET` exibe a OS para conferência sem consumir o token; requisição `POST` valida origem, assinatura PNG e nome do signatário, marca como `signed` e invalida o token imediatamente (uso único).
- **Impressão Autorizada (`front/print.php`):**
  - Visualização limpa com `@media print`, acessível apenas para usuários autenticados com permissão de leitura no chamado e na entidade correspondente.

## Matriz de Compatibilidade

| Versão do GLPI | Versão do PHP | Status |
| :--- | :--- | :--- |
| **GLPI 10.0.x** (`10.0.0` – `10.0.99`) | PHP `7.4` ou superior | Suportado |
| **GLPI 11.0.x** (`11.0.0` – `11.0.99`) | PHP `8.2` ou superior | Suportado |
| **GLPI 12.0.x** (`12.0.0` – `12.0.99`) | PHP `8.3` ou superior | Suportado |

## Instalação

1. Extraia o diretório `orderservice` dentro da pasta `plugins/` (ou `marketplace/` no GLPI 10) da sua instalação do GLPI.
2. Acesse **Configurar > Plug-ins** no GLPI.
3. Clique em **Instalar** e em seguida em **Ativar** no plugin **Order Service**.
4. Acesse **Configurar > Plug-ins > Order Service** para informar os dados da empresa, moeda, taxa padrão de deslocamento, validade dos links remotos e termos de rodapé.

## Licença

Distribuído sob a licença **GNU General Public License v3.0 or later (`GPL-3.0-or-later` / `GPLv3+`)**.  
Copyright (C) 2026 by G. Martins.
