<?php

/**
 * ------------------------------------------------------------------------
 * Order Service - Suíte de testes unitários standalone
 *
 * Copyright (C) 2026 by G. Martins (GPLv3+)
 * ------------------------------------------------------------------------
 */

require_once __DIR__ . '/bootstrap.php';

function assertSame($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            sprintf(
                "Falha em [%s]: esperado %s, obtido %s",
                $label,
                var_export($expected, true),
                var_export($actual, true)
            )
        );
    }
}

function assertTrue($condition, string $label): void
{
    if ($condition !== true) {
        throw new RuntimeException(sprintf("Falha em [%s]: esperado true", $label));
    }
}

function buildValidPngDataUri(int $extraPaddingBytes = 0): string
{
    $ihdrData = pack('NNCCCCC', 1, 1, 8, 6, 0, 0, 0);
    $ihdr     = pack('N', 13) . 'IHDR' . $ihdrData . pack('N', crc32('IHDR' . $ihdrData));
    $iend     = pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));
    $extra    = $extraPaddingBytes > 0 ? str_repeat("\x00", $extraPaddingBytes) : '';
    $binary   = "\x89PNG\r\n\x1a\n" . $ihdr . $extra . $iend;
    return 'data:image/png;base64,' . base64_encode($binary);
}

function resetEnvironment(): void
{
    global $DB;
    $DB->reset();
    Config::$store = [];
    Session::reset();
    Ticket::reset();
    Plugin::$webDirCalled = false;
    if (property_exists('Html', 'rightErrorDisplayed')) {
        Html::$rightErrorDisplayed = false;
    }
    Html::$lastRedirect = null;
    $_GET               = [];
    $_POST              = [];
    $_SERVER            = [];
    PluginOrderserviceOrder::setNowProviderForTest(null);
    plugin_orderservice_install();

    $DB->insert('glpi_users', [
        'id'        => 2,
        'name'      => 'tech1',
        'firstname' => 'Carlos',
        'realname'  => 'Silva',
    ]);
    $DB->insert('glpi_tickets', [
        'id'          => 10,
        'name'        => 'Servidor sem acesso &amp; lento',
        'entities_id' => 0,
    ]);
    $DB->insert('glpi_tickets', [
        'id'          => 20,
        'name'        => 'Troca de switch',
        'entities_id' => 5,
    ]);
    Ticket::$mockTickets[10] = ['id' => 10, 'name' => 'Servidor sem acesso', 'entities_id' => 0];
    Ticket::$mockTickets[20] = ['id' => 20, 'name' => 'Troca de switch', 'entities_id' => 5];
}

function test_plugin_manifest_and_hooks(): void
{
    global $PLUGIN_HOOKS;
    resetEnvironment();

    plugin_init_orderservice();
    $info = plugin_version_orderservice();

    assertSame('1.0.0', $info['version'], 'versao do plugin');
    assertSame('G. Martins', $info['author'], 'autor do plugin');
    assertSame('GPLv3+', $info['license'], 'licenca do plugin');
    assertSame('10.0.0', $info['requirements']['glpi']['min'], 'GLPI min');
    assertSame('12.0.99', $info['requirements']['glpi']['max'], 'GLPI max');
    assertSame('7.4', $info['requirements']['php']['min'], 'PHP min');
    assertTrue(plugin_orderservice_check_prerequisites(), 'check_prerequisites');
    assertTrue(plugin_orderservice_check_config(), 'check_config');

    assertSame(true, $PLUGIN_HOOKS['csrf_compliant']['orderservice'] ?? null, 'csrf_compliant');
    assertSame('front/config.form.php', $PLUGIN_HOOKS['config_page']['orderservice'] ?? null, 'config_page');
    assertSame(['css/orderservice.css'], $PLUGIN_HOOKS['add_css']['orderservice'] ?? null, 'add_css');
    assertSame(['js/orderservice.js'], $PLUGIN_HOOKS['add_javascript']['orderservice'] ?? null, 'add_javascript');
    assertSame(['Ticket'], Plugin::$registered['PluginOrderserviceTab']['addtabon'] ?? null, 'tab registrada em Ticket');
    assertSame([PluginOrderserviceOrder::class, 'purgeTicketOrders'], $PLUGIN_HOOKS['item_purge']['orderservice']['Ticket'] ?? null, 'item_purge hook');
}

function test_legacy_query_only_db_on_glpi_10_0_5(): void
{
    global $DB;
    resetEnvironment();

    $origDb = $DB;
    try {
        $legacyDb = new LegacyQueryOnlyDB();
        assertSame(false, method_exists($legacyDb, 'doQuery'), 'LegacyQueryOnlyDB nao possui doQuery');

        $DB = $legacyDb;
        PluginOrderserviceOrder::installSchema();

        assertTrue($legacyDb->tableExists(PluginOrderserviceOrder::TABLE_ORDERS), 'cria tabela orders via query()');
        assertTrue($legacyDb->tableExists(PluginOrderserviceOrder::TABLE_ITEMS), 'cria tabela items via query()');
        assertSame(2, count($legacyDb->queries), '2 comandos CREATE TABLE executados');

        PluginOrderserviceOrder::uninstallSchema();
        assertSame(false, $legacyDb->tableExists(PluginOrderserviceOrder::TABLE_ORDERS), 'remove tabela orders via query()');
        assertSame(false, $legacyDb->tableExists(PluginOrderserviceOrder::TABLE_ITEMS), 'remove tabela items via query()');
        assertSame(4, count($legacyDb->queries), '2 comandos DROP TABLE executados');
    } finally {
        $DB = $origDb;
    }
}

function test_sequential_order_numbering_and_items_cent_math(): void
{
    resetEnvironment();
    PluginOrderserviceOrder::setNowProviderForTest(static function (): int {
        return strtotime('2026-11-17 12:00:00 UTC');
    });

    assertSame(123456, PluginOrderserviceOrder::parseDecimalToInt('1.234,56'), 'parseDecimal pt-BR');
    assertSame(1999, PluginOrderserviceOrder::parseDecimalToInt('19.99'), 'parseDecimal en-US');
    assertSame(250, PluginOrderserviceOrder::parseDecimalToInt('2,5'), 'parseDecimal 1 casa');
    assertSame(101, PluginOrderserviceOrder::parseDecimalToInt('1.005'), 'parseDecimal half-up');
    assertSame(0, PluginOrderserviceOrder::parseDecimalToInt('-50,00'), 'parseDecimal rejeita negativo');

    assertSame(15000, PluginOrderserviceOrder::computeItemTotalCents(150, 10000), '1.50 x 100.00 = 150.00');
    assertSame(330, PluginOrderserviceOrder::computeItemTotalCents(33, 1001), '0.33 x 10.01 = 3.30');
    assertSame(2, PluginOrderserviceOrder::computeItemTotalCents(50, 3), '0.50 x 0.03 = 0.02');

    $o1 = PluginOrderserviceOrder::createOrder([
        'tickets_id'          => 10,
        'entities_id'         => 0,
        'technician_users_id' => 2,
        'summary'             => 'Troca de fonte',
        'travel_cents'        => 5000,
    ]);
    assertSame('OS-2026-000001', $o1['order_number'] ?? null, 'primeira OS sequencial');
    assertSame(5000, (int) ($o1['total_cents'] ?? -1), 'total inicial igual ao deslocamento');

    $o2 = PluginOrderserviceOrder::createOrder([
        'tickets_id'          => 10,
        'entities_id'         => 0,
        'technician_users_id' => 2,
        'summary'             => 'Segunda visita',
        'travel_cents'        => 0,
    ]);
    assertSame('OS-2026-000002', $o2['order_number'] ?? null, 'segunda OS sequencial');

    $o1Id = (int) $o1['id'];
    assertTrue(PluginOrderserviceOrder::addItem($o1Id, 'service', 'Mao de obra especializada', 150, 12000), 'adiciona servico');
    assertTrue(PluginOrderserviceOrder::addItem($o1Id, 'material', 'Fonte ATX 600W', 100, 35050), 'adiciona material');
    assertTrue(PluginOrderserviceOrder::addItem($o1Id, 'travel', 'Pedagio rodovia', 200, 1250), 'adiciona item travel');

    $reloaded = PluginOrderserviceOrder::getById($o1Id);
    // 18000 + 35050 + 2500 = 55550 subtotal; + 5000 travel_cents = 60550
    assertSame(55550, (int) $reloaded['subtotal_cents'], 'subtotal dos itens em centavos');
    assertSame(60550, (int) $reloaded['total_cents'], 'total geral em centavos');
    assertSame('R$ 605,50', PluginOrderserviceOrder::formatMoneyCents((int) $reloaded['total_cents'], 'R$'), 'formatacao monetaria');

    $items = PluginOrderserviceOrder::getItemsForOrder($o1Id);
    assertSame(3, count($items), '3 itens gravados');
    assertTrue(PluginOrderserviceOrder::deleteItem($o1Id, (int) $items[2]['id']), 'remove terceiro item');

    $afterDel = PluginOrderserviceOrder::getById($o1Id);
    assertSame(53050, (int) $afterDel['subtotal_cents'], 'subtotal recalculado apos remocao');
    assertSame(58050, (int) $afterDel['total_cents'], 'total recalculado apos remocao');

    assertTrue(PluginOrderserviceOrder::signLocally($o1Id, buildValidPngDataUri(), 'Maria Souza'), 'assinatura presencial');
    assertSame(false, PluginOrderserviceOrder::addItem($o1Id, 'service', 'Bloqueado', 100, 1000), 'bloqueia adicao apos assinatura');
    assertSame(false, PluginOrderserviceOrder::updateOrder($o1Id, ['summary' => 'Alterado']), 'bloqueia edicao apos assinatura');
}

function test_strict_png_signature_validation(): void
{
    resetEnvironment();

    $valid = buildValidPngDataUri();
    $canon = PluginOrderserviceOrder::validateSignatureDataUri($valid);
    assertTrue(is_string($canon) && strpos($canon, 'data:image/png;base64,') === 0, 'aceita PNG binario valido');

    $svgPayload = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    assertSame(null, PluginOrderserviceOrder::validateSignatureDataUri($svgPayload), 'rejeita SVG data URI');

    $htmlPayload = 'data:text/html;base64,' . base64_encode('<script>alert(1)</script>');
    assertSame(null, PluginOrderserviceOrder::validateSignatureDataUri($htmlPayload), 'rejeita HTML data URI');

    $fakePngWithPngPrefix = 'data:image/png;base64,' . base64_encode('<html>not a png image at all</html>');
    assertSame(null, PluginOrderserviceOrder::validateSignatureDataUri($fakePngWithPngPrefix), 'rejeita PNG falso sem magic bytes');

    $badChunkPng = 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n\x00\x00\x00\x0dXXXX1234567890123456");
    assertSame(null, PluginOrderserviceOrder::validateSignatureDataUri($badChunkPng), 'rejeita PNG sem chunk IHDR');

    $oversized = buildValidPngDataUri(PluginOrderserviceOrder::MAX_SIGNATURE_BYTES + 100);
    assertSame(null, PluginOrderserviceOrder::validateSignatureDataUri($oversized), 'rejeita PNG acima do limite de 256 KB');
}

function test_remote_sign_token_two_step_and_one_time_consumption(): void
{
    resetEnvironment();
    $now = strtotime('2026-11-17 12:00:00 UTC');
    PluginOrderserviceOrder::setNowProviderForTest(static function () use (&$now): int {
        return $now;
    });

    $order = PluginOrderserviceOrder::createOrder([
        'tickets_id'          => 10,
        'entities_id'         => 0,
        'technician_users_id' => 2,
        'summary'             => 'Manutencao preventiva',
    ]);
    $orderId = (int) $order['id'];

    $rawToken = PluginOrderserviceOrder::generateSignToken($orderId);
    assertTrue(is_string($rawToken) && strlen($rawToken) === 64, 'token bruto com 64 hex chars');

    $stored = PluginOrderserviceOrder::getById($orderId);
    assertSame(PluginOrderserviceOrder::STATUS_PENDING, $stored['status'], 'status muda para pending_signature');
    assertSame(hash('sha256', $rawToken), $stored['token_hash'], 'banco armazena apenas SHA-256');
    assertTrue($stored['token_hash'] !== $rawToken, 'token bruto nao fica no banco');

    // GET repetido nao pode consumir o token
    $lookup1 = PluginOrderserviceOrder::findByToken($rawToken);
    $lookup2 = PluginOrderserviceOrder::findByToken($rawToken);
    assertSame($orderId, (int) ($lookup1['id'] ?? 0), 'GET 1 encontra OS');
    assertSame($orderId, (int) ($lookup2['id'] ?? 0), 'GET 2 ainda encontra OS sem consumir');

    // POST consome o token uma unica vez
    $res = PluginOrderserviceOrder::signByToken($rawToken, buildValidPngDataUri(), 'Joao Cliente');
    assertTrue($res['ok'] === true, 'POST com token valido conclui assinatura');

    $afterSign = PluginOrderserviceOrder::getById($orderId);
    assertSame(PluginOrderserviceOrder::STATUS_SIGNED, $afterSign['status'], 'status final signed');
    assertSame('', $afterSign['token_hash'], 'token_hash invalidado apos consumo');
    assertSame('Joao Cliente', $afterSign['signed_by_name'], 'nome do signatario salvo');

    // Reutilizacao do mesmo token deve falhar
    $replay = PluginOrderserviceOrder::signByToken($rawToken, buildValidPngDataUri(), 'Atacante');
    assertSame(false, $replay['ok'], 'replay com mesmo token bloqueado');

    // Expiracao do token
    $order2   = PluginOrderserviceOrder::createOrder(['tickets_id' => 10, 'entities_id' => 0]);
    $token2   = PluginOrderserviceOrder::generateSignToken((int) $order2['id']);
    $now     += 73 * 3600;
    assertSame(null, PluginOrderserviceOrder::findByToken((string) $token2), 'token expirado rejeitado');
}

function test_ticket_purge_cleans_orders_and_items(): void
{
    resetEnvironment();

    $o10 = PluginOrderserviceOrder::createOrder(['tickets_id' => 10, 'entities_id' => 0]);
    PluginOrderserviceOrder::addItem((int) $o10['id'], 'service', 'Servico T10', 100, 5000);

    $o20 = PluginOrderserviceOrder::createOrder(['tickets_id' => 20, 'entities_id' => 5]);
    PluginOrderserviceOrder::addItem((int) $o20['id'], 'material', 'Peca T20', 100, 8000);

    $t10 = new Ticket();
    $t10->getFromDB(10);
    PluginOrderserviceOrder::purgeTicketOrders($t10);

    assertSame([], PluginOrderserviceOrder::getOrdersForTicket(10), 'ordens do ticket 10 removidas no purge');
    assertSame([], PluginOrderserviceOrder::getItemsForOrder((int) $o10['id']), 'itens do ticket 10 removidos no purge');
    assertSame(1, count(PluginOrderserviceOrder::getOrdersForTicket(20)), 'ordens do ticket 20 preservadas');
    assertSame(1, count(PluginOrderserviceOrder::getItemsForOrder((int) $o20['id'])), 'itens do ticket 20 preservados');
}

function test_tab_auth_entity_checks_and_endpoints(): void
{
    resetEnvironment();

    $tab = new PluginOrderserviceTab();
    $t10 = new Ticket();
    $t10->getFromDB(10);

    $t20 = new Ticket();
    $t20->getFromDB(20);
    Session::$entityAccess[5] = false;

    assertSame('Service Orders', $tab->getTabNameForItem($t10), 'aba visivel para ticket autorizado');
    assertSame('', $tab->getTabNameForItem($t20), 'aba oculta quando usuario nao tem acesso a entidade');

    Ticket::$viewableOverrides[10] = false;
    assertSame('', $tab->getTabNameForItem($t10), 'aba oculta quando canViewItem() retorna false');
    Ticket::$viewableOverrides[10] = true;

    // Testa POST em config.form.php
    $_SERVER = [
        'REQUEST_METHOD' => 'POST',
        'HTTP_HOST'      => 'glpi.local',
        'HTTP_ORIGIN'    => 'https://glpi.local',
    ];
    $_POST = [
        'update'                => '1',
        'company_name'          => 'Gartin Tech',
        'company_document'      => '12.345.678/0001-99',
        'currency_symbol'       => 'R$',
        'default_travel_amount' => '75,50',
        'allow_public_sign'     => '1',
        'token_ttl_hours'       => '48',
        'terms_footer'          => 'Garantia 90 dias',
    ];
    include GLPI_ROOT . '/orderservice/front/config.form.php';
    $cfg = PluginOrderserviceConfig::getConfig();
    assertSame('Gartin Tech', $cfg['company_name'], 'config salva nome empresa');
    assertSame(7550, $cfg['default_travel_cents'], 'config salva deslocamento em centavos');
    assertSame(48, $cfg['token_ttl_hours'], 'config salva TTL');

    // Testa criacao via order.form.php e geracao de token + protecao IDOR
    $_POST = [
        'tickets_id'    => '10',
        'os_action'     => 'create_order',
        'summary'       => 'Troca de disco SSD',
        'travel_amount' => '40,00',
    ];
    include GLPI_ROOT . '/orderservice/front/order.form.php';
    $orders10 = PluginOrderserviceOrder::getOrdersForTicket(10);
    assertSame(1, count($orders10), 'order.form.php cria OS no ticket 10');
    $createdId = (int) $orders10[0]['id'];

    // Tenta alterar OS do ticket 10 passando tickets_id=20 (IDOR)
    Session::$entityAccess[5] = true;
    $_POST = [
        'tickets_id' => '20',
        'orders_id'  => (string) $createdId,
        'os_action'  => 'cancel_order',
    ];
    if (property_exists('Html', 'rightErrorDisplayed')) {
        Html::$rightErrorDisplayed = false;
    }
    include GLPI_ROOT . '/orderservice/front/order.form.php';
    $stillDraft = PluginOrderserviceOrder::getById($createdId);
    assertSame(PluginOrderserviceOrder::STATUS_DRAFT, $stillDraft['status'], 'IDOR entre tickets bloqueado');

    // Gera token via order.form.php -> exibe uma vez na aba
    $_POST = [
        'tickets_id' => '10',
        'orders_id'  => (string) $createdId,
        'os_action'  => 'generate_token',
    ];
    include GLPI_ROOT . '/orderservice/front/order.form.php';
    $flashUrl = $_SESSION['plugin_orderservice_last_sign'][10]['url'] ?? '';
    assertTrue(strpos($flashUrl, '/front/sign.php?t=') !== false, 'flash contem link remoto com token');

    parse_str((string) parse_url($flashUrl, PHP_URL_QUERY), $q);
    $rawToken = (string) ($q['t'] ?? '');

    ob_start();
    PluginOrderserviceTab::displayTabContentForItem($t10);
    $tabHtml = (string) ob_get_clean();
    assertTrue(strpos($tabHtml, $rawToken) !== false, 'aba exibe link uma vez');
    assertSame(false, isset($_SESSION['plugin_orderservice_last_sign'][10]), 'flash removido apos exibicao');

    // Testa htmlLang()
    $_SESSION['glpilanguage'] = 'en_US';
    assertSame('en-US', PluginOrderserviceCompat::htmlLang(), 'htmlLang normaliza en_US');
    unset($_SESSION['glpilanguage']);
    assertSame('pt-BR', PluginOrderserviceCompat::htmlLang(), 'htmlLang padrao pt-BR');

    // Testa POST direto em sign.php sem nonce de sessao (deve falhar fechado sem consumir token)
    unset($_SESSION['plugin_orderservice_sign_nonce']);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET                    = [];
    $_POST                   = [
        'sign_token'       => $rawToken,
        'sign_nonce'       => '',
        'signed_by_name'   => 'Forjado',
        'client_signature' => buildValidPngDataUri(),
    ];
    ob_start();
    include GLPI_ROOT . '/orderservice/front/sign.php';
    $forgedHtml = (string) ob_get_clean();
    assertTrue(strpos($forgedHtml, 'Invalid or expired form session') !== false, 'sign.php bloqueia POST direto sem nonce de sessao');
    $stillPending = PluginOrderserviceOrder::getById($createdId);
    assertSame(PluginOrderserviceOrder::STATUS_PENDING, $stillPending['status'], 'token preservado quando nonce ausente');

    // Testa allow_public_sign = 0 bloqueando acesso anonimo em sign.php
    PluginOrderserviceConfig::saveConfig(['allow_public_sign' => 0]);
    $prevUserId            = Session::$loginUserId;
    Session::$loginUserId  = 0;
    if (property_exists('Html', 'rightErrorDisplayed')) {
        Html::$rightErrorDisplayed = false;
    }
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET                    = ['t' => $rawToken];
    ob_start();
    include GLPI_ROOT . '/orderservice/front/sign.php';
    ob_end_clean();
    if (property_exists('Html', 'rightErrorDisplayed')) {
        assertTrue(Html::$rightErrorDisplayed === true, 'sign.php nega anonimo quando allow_public_sign=0');
    }
    Session::$loginUserId = $prevUserId;
    PluginOrderserviceConfig::saveConfig(['allow_public_sign' => 1]);

    // Testa front/sign.php GET + POST + Html::closeForm + escape XSS
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET                    = ['t' => $rawToken];
    $_POST                   = [];
    ob_start();
    include GLPI_ROOT . '/orderservice/front/sign.php';
    $signGetHtml = (string) ob_get_clean();
    assertTrue(strpos($signGetHtml, 'OS-2026-000001') !== false, 'sign.php GET exibe numero da OS');
    assertTrue(strpos($signGetHtml, '_glpi_csrf_token') !== false, 'sign.php emite _glpi_csrf_token via Html::closeForm');
    assertTrue(strpos($signGetHtml, 'data-status-ok=') !== false, 'sign.php emite data-status-ok localizado');
    $nonce = (string) ($_SESSION['plugin_orderservice_sign_nonce'] ?? '');
    assertTrue($nonce !== '', 'sign.php GET gera nonce de formulario');

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET                    = [];
    $_POST                   = [
        'sign_token'       => $rawToken,
        'sign_nonce'       => $nonce,
        'signed_by_name'   => 'Cliente <script>alert(1)</script>',
        'client_signature' => buildValidPngDataUri(),
    ];
    ob_start();
    include GLPI_ROOT . '/orderservice/front/sign.php';
    $signPostHtml = (string) ob_get_clean();
    assertTrue(strpos($signPostHtml, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false, 'sign.php escapa nome do signatario');
    assertSame(false, strpos($signPostHtml, '<script>alert(1)</script>'), 'sign.php sem XSS');

    // Testa front/print.php autorizado e bloqueado sem acesso
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET                    = ['id' => (string) $createdId];
    ob_start();
    include GLPI_ROOT . '/orderservice/front/print.php';
    $printHtml = (string) ob_get_clean();
    assertTrue(strpos($printHtml, 'data:image/png;base64,') !== false, 'print.php renderiza assinatura PNG validada');
    assertTrue(strpos($printHtml, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false, 'print.php escapa saida HTML');

    Session::$entityAccess[0] = false;
    if (property_exists('Html', 'rightErrorDisplayed')) {
        Html::$rightErrorDisplayed = false;
    }
    ob_start();
    include GLPI_ROOT . '/orderservice/front/print.php';
    ob_end_clean();
    if (property_exists('Html', 'rightErrorDisplayed')) {
        assertTrue(Html::$rightErrorDisplayed === true, 'print.php bloqueia entidade sem permissao');
    }
    Session::$entityAccess[0] = true;
}

/**
 * @param array<string, string> $env
 */
function runIsolatedPhpAssertion(array $env, string $code): void
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $cmdEnv = array_merge($_ENV, $env);
    foreach ($_SERVER as $k => $v) {
        if (is_string($v) && !isset($cmdEnv[$k])) {
            $cmdEnv[$k] = $v;
        }
    }

    $oneLineCode = trim((string) preg_replace('/\s+/', ' ', $code));
    $process     = proc_open([PHP_BINARY, '-r', $oneLineCode], $descriptorSpec, $pipes, dirname(__DIR__), $cmdEnv);
    if (!is_resource($process)) {
        throw new RuntimeException('Falha ao iniciar subprocesso PHP');
    }

    fclose($pipes[0]);
    $stdout   = stream_get_contents($pipes[1]);
    $stderr   = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        throw new RuntimeException(
            sprintf("Subprocesso falhou (codigo %d). STDOUT: %s | STDERR: %s", $exitCode, $stdout, $stderr)
        );
    }
}

function test_glpi_10_11_12_subprocesses(): void
{
    // 1) GLPI 10.0.18 (untyped $rightname, public/ asset prefix, fromCore preserva barras invertidas)
    runIsolatedPhpAssertion(
        [
            'ORDERSERVICE_TEST_TYPED_RIGHTNAME' => '0',
            'ORDERSERVICE_TEST_GLPI_VERSION'    => '10.0.18',
        ],
        '
        require "tests/bootstrap.php";
        if (PluginOrderserviceCompat::typedRightname() !== false) { exit(1); }
        $refCfg = new ReflectionProperty("PluginOrderserviceConfig", "rightname");
        $refTab = new ReflectionProperty("PluginOrderserviceTab", "rightname");
        if ($refCfg->hasType() || $refTab->hasType()) { exit(2); }
        if (PluginOrderserviceCompat::glpiMajor() !== 10) { exit(3); }
        if (PluginOrderserviceCompat::assetPath("css/orderservice.css") !== "public/css/orderservice.css") { exit(4); }
        if (PluginOrderserviceCompat::assetPath("js/orderservice.js") !== "public/js/orderservice.js") { exit(5); }
        $bs = chr(92);
        if (PluginOrderserviceCompat::fromCore("DOMAIN" . $bs . "user &amp; &#039;admin&#039;") !== "DOMAIN" . $bs . "user & \x27admin\x27") { exit(6); }
        PluginOrderserviceCompat::denyAccess();
        if (Html::$rightErrorDisplayed !== true) { exit(7); }
        exit(0);
        '
    );

    // 2) GLPI 11.0.0 (webPath retorna /plugins/orderservice sem chamar Plugin::getWebDir + Firewall registrado)
    runIsolatedPhpAssertion(
        [
            'ORDERSERVICE_TEST_TYPED_RIGHTNAME' => '1',
            'ORDERSERVICE_TEST_GLPI_VERSION'    => '11.0.0',
        ],
        '
        require "tests/bootstrap.php";
        if (PluginOrderserviceCompat::typedRightname() !== true) { exit(1); }
        if (PluginOrderserviceCompat::glpiMajor() !== 11) { exit(2); }
        if (PluginOrderserviceCompat::assetPath("css/orderservice.css") !== "css/orderservice.css") { exit(3); }
        if (PluginOrderserviceCompat::assetPath("js/orderservice.js") !== "js/orderservice.js") { exit(4); }
        if (PluginOrderserviceCompat::webPath(false) !== "/plugins/orderservice") { exit(5); }
        if (PluginOrderserviceCompat::webPath(true) !== "https://glpi.local/plugins/orderservice") { exit(6); }
        if (Plugin::$webDirCalled !== false) { exit(7); }
        if (empty(\Glpi\Http\Firewall::$strategies[0]) || \Glpi\Http\Firewall::$strategies[0]["plugin"] !== "orderservice") { exit(8); }
        if (\Glpi\Http\Firewall::$strategies[0]["pattern"] !== "#^/front/sign\\.php$#") { exit(9); }
        exit(0);
        '
    );

    // 3) GLPI 12.0.0 (typed $rightname + AccessDeniedHttpException em denyAccess + Firewall registrado)
    runIsolatedPhpAssertion(
        [
            'ORDERSERVICE_TEST_TYPED_RIGHTNAME' => '1',
            'ORDERSERVICE_TEST_GLPI_VERSION'    => '12.0.0',
        ],
        '
        require "tests/bootstrap.php";
        $refCfg = new ReflectionProperty("PluginOrderserviceConfig", "rightname");
        $refTab = new ReflectionProperty("PluginOrderserviceTab", "rightname");
        if (!$refCfg->hasType() || !$refTab->hasType()) { exit(1); }
        if (PluginOrderserviceCompat::glpiMajor() !== 12) { exit(2); }
        if (empty(\Glpi\Http\Firewall::$strategies[0]) || \Glpi\Http\Firewall::$strategies[0]["strategy"] !== \Glpi\Http\Firewall::STRATEGY_NO_CHECK) { exit(4); }
        $threw = false;
        try {
            PluginOrderserviceCompat::denyAccess();
        } catch (\Glpi\Exception\Http\AccessDeniedHttpException $e) {
            $threw = true;
        }
        if (!$threw) { exit(3); }
        exit(0);
        '
    );

    // 4) Uninstall limpa tabelas e configuracoes
    global $DB;
    resetEnvironment();
    assertTrue($DB->tableExists(PluginOrderserviceOrder::TABLE_ORDERS), 'orders existe apos install');
    assertTrue($DB->tableExists(PluginOrderserviceOrder::TABLE_ITEMS), 'items existe apos install');
    plugin_orderservice_uninstall();
    assertSame(false, $DB->tableExists(PluginOrderserviceOrder::TABLE_ORDERS), 'orders removida no uninstall');
    assertSame(false, $DB->tableExists(PluginOrderserviceOrder::TABLE_ITEMS), 'items removida no uninstall');
    assertSame([], Config::getConfigurationValues(PluginOrderserviceConfig::CONTEXT), 'config removida no uninstall');
}

$tests = [
    'test_plugin_manifest_and_hooks',
    'test_legacy_query_only_db_on_glpi_10_0_5',
    'test_sequential_order_numbering_and_items_cent_math',
    'test_strict_png_signature_validation',
    'test_remote_sign_token_two_step_and_one_time_consumption',
    'test_ticket_purge_cleans_orders_and_items',
    'test_tab_auth_entity_checks_and_endpoints',
    'test_glpi_10_11_12_subprocesses',
];

$passed = 0;
foreach ($tests as $testFn) {
    $testFn();
    $passed++;
    echo "[OK] {$testFn}\n";
}

echo sprintf("Todos os %d testes passaram com sucesso.\n", $passed);
