# Changelog

Todas as alterações relevantes deste projeto são documentadas neste arquivo.

## [1.0.0] - 2026-11-17

### Adicionado
- Aba **Ordens de Serviço** (`PluginOrderserviceTab`) em `Ticket` com geração de numeração sequencial `OS-YYYY-000001`.
- Gestão de itens (`service`, `material`, `travel`) e taxa de deslocamento com cálculo exato em centavos inteiros.
- Captura de assinatura presencial em `<canvas>` (JavaScript puro) com validação binária estrita de PNG (`\x89PNG\r\n\x1a\n`, `IHDR`, limite de 256 KB).
- Fluxo de assinatura remota de uso único em duas etapas (`front/sign.php` com armazenamento exclusivo do hash `SHA-256`).
- Tela de impressão autorizada (`front/print.php`) com folha de estilos `@media print` e painel de configuração (`front/config.form.php`).
- Limpeza automática de ordens e itens ao expurgar chamados (`Ticket` `item_purge`).
- Compatibilidade unificada para GLPI 10.0.0 a 12.0.99 e PHP 7.4 a 8.3+.
