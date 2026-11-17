<?php

/**
 * ------------------------------------------------------------------------
 * Order Service - Modelo de dados, numeração, itens e assinatura PNG
 *
 * Copyright (C) 2026 by G. Martins
 * ------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Order Service.
 *
 * Order Service is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Order Service is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 * ------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginOrderserviceOrder
{
    public const TABLE_ORDERS = 'glpi_plugin_orderservice_orders';
    public const TABLE_ITEMS  = 'glpi_plugin_orderservice_items';

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PENDING   = 'pending_signature';
    public const STATUS_SIGNED    = 'signed';
    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_SERVICE  = 'service';
    public const TYPE_MATERIAL = 'material';
    public const TYPE_TRAVEL   = 'travel';

    public const MAX_SIGNATURE_BYTES = 262144;
    public const PNG_DATA_URI_PREFIX = 'data:image/png;base64,';
    public const PNG_MAGIC           = "\x89PNG\r\n\x1a\n";

    /** @var callable|null */
    private static $nowProvider = null;

    /**
     * Permite injetar relógio determinístico em testes automatizados.
     */
    public static function setNowProviderForTest(?callable $provider): void
    {
        self::$nowProvider = $provider;
    }

    /**
     * Retorna timestamp Unix atual.
     */
    public static function nowTs(): int
    {
        if (self::$nowProvider !== null) {
            return (int) call_user_func(self::$nowProvider);
        }
        return time();
    }

    /**
     * Cria tabelas do plugin usando Compat::execDdl.
     */
    public static function installSchema(): void
    {
        global $DB;

        if (!isset($DB) || !is_object($DB)) {
            return;
        }

        $charset   = class_exists('DBConnection') && method_exists('DBConnection', 'getDefaultCharset')
            ? DBConnection::getDefaultCharset()
            : 'utf8mb4';
        $collation = class_exists('DBConnection') && method_exists('DBConnection', 'getDefaultCollation')
            ? DBConnection::getDefaultCollation()
            : 'utf8mb4_unicode_ci';

        if (!$DB->tableExists(self::TABLE_ORDERS)) {
            $sqlOrders = sprintf(
                "CREATE TABLE IF NOT EXISTS `%s` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `order_number` VARCHAR(32) NOT NULL,
                    `tickets_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `technician_users_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `summary` TEXT NULL,
                    `travel_cents` INT NOT NULL DEFAULT 0,
                    `subtotal_cents` INT NOT NULL DEFAULT 0,
                    `total_cents` INT NOT NULL DEFAULT 0,
                    `status` VARCHAR(32) NOT NULL DEFAULT 'draft',
                    `token_hash` VARCHAR(64) NOT NULL DEFAULT '',
                    `token_expires_at` TIMESTAMP NULL DEFAULT NULL,
                    `tech_signature` MEDIUMTEXT NULL,
                    `client_signature` MEDIUMTEXT NULL,
                    `signed_by_name` VARCHAR(255) NOT NULL DEFAULT '',
                    `signed_at` TIMESTAMP NULL DEFAULT NULL,
                    `date_creation` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    `date_mod` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_order_number` (`order_number`),
                    KEY `idx_tickets_id` (`tickets_id`),
                    KEY `idx_entities_id` (`entities_id`),
                    KEY `idx_status` (`status`),
                    KEY `idx_token_hash` (`token_hash`)
                ) ENGINE=InnoDB DEFAULT CHARSET=%s COLLATE=%s ROW_FORMAT=DYNAMIC",
                self::TABLE_ORDERS,
                $charset,
                $collation
            );
            PluginOrderserviceCompat::execDdl($DB, $sqlOrders);
        }

        if (!$DB->tableExists(self::TABLE_ITEMS)) {
            $sqlItems = sprintf(
                "CREATE TABLE IF NOT EXISTS `%s` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `orders_id` INT UNSIGNED NOT NULL,
                    `item_type` VARCHAR(32) NOT NULL DEFAULT 'service',
                    `description` VARCHAR(255) NOT NULL DEFAULT '',
                    `quantity_x100` INT NOT NULL DEFAULT 100,
                    `unit_price_cents` INT NOT NULL DEFAULT 0,
                    `total_cents` INT NOT NULL DEFAULT 0,
                    `date_creation` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_orders_id` (`orders_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=%s COLLATE=%s ROW_FORMAT=DYNAMIC",
                self::TABLE_ITEMS,
                $charset,
                $collation
            );
            PluginOrderserviceCompat::execDdl($DB, $sqlItems);
        }
    }

    /**
     * Remove tabelas do plugin na desinstalação.
     */
    public static function uninstallSchema(): void
    {
        global $DB;

        if (!isset($DB) || !is_object($DB)) {
            return;
        }

        PluginOrderserviceCompat::execDdl($DB, sprintf('DROP TABLE IF EXISTS `%s`', self::TABLE_ITEMS));
        PluginOrderserviceCompat::execDdl($DB, sprintf('DROP TABLE IF EXISTS `%s`', self::TABLE_ORDERS));
    }

    /**
     * Hook item_purge em Ticket: remove ordens e itens vinculados ao chamado expurgado.
     *
     * @param mixed $item
     */
    public static function purgeTicketOrders($item): void
    {
        global $DB;

        if (!isset($DB) || !is_object($DB)) {
            return;
        }

        $ticketsId = 0;
        if (is_object($item) && method_exists($item, 'getID')) {
            $ticketsId = (int) $item->getID();
        } elseif (is_numeric($item)) {
            $ticketsId = (int) $item;
        }

        if ($ticketsId <= 0) {
            return;
        }

        $orders = self::getOrdersForTicket($ticketsId);
        foreach ($orders as $order) {
            $orderId = (int) ($order['id'] ?? 0);
            if ($orderId > 0) {
                $DB->delete(self::TABLE_ITEMS, ['orders_id' => $orderId]);
            }
        }

        $DB->delete(self::TABLE_ORDERS, ['tickets_id' => $ticketsId]);
    }

    /**
     * Lista status válidos de ordem de serviço.
     *
     * @return array<int, string>
     */
    public static function validStatuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_PENDING,
            self::STATUS_SIGNED,
            self::STATUS_CANCELLED,
        ];
    }

    /**
     * Lista tipos válidos de item.
     *
     * @return array<int, string>
     */
    public static function validItemTypes(): array
    {
        return [
            self::TYPE_SERVICE,
            self::TYPE_MATERIAL,
            self::TYPE_TRAVEL,
        ];
    }

    public static function getStatusLabel(string $status): string
    {
        switch ($status) {
            case self::STATUS_DRAFT:
                return __('Draft', 'orderservice');
            case self::STATUS_PENDING:
                return __('Pending signature', 'orderservice');
            case self::STATUS_SIGNED:
                return __('Signed', 'orderservice');
            case self::STATUS_CANCELLED:
                return __('Cancelled', 'orderservice');
            default:
                return $status;
        }
    }

    public static function getItemTypeLabel(string $type): string
    {
        switch ($type) {
            case self::TYPE_SERVICE:
                return __('Service', 'orderservice');
            case self::TYPE_MATERIAL:
                return __('Material', 'orderservice');
            case self::TYPE_TRAVEL:
                return __('Travel', 'orderservice');
            default:
                return $type;
        }
    }

    /**
     * Verifica se a ordem ainda pode ser editada/assinada.
     */
    public static function isEditableStatus(string $status): bool
    {
        return $status === self::STATUS_DRAFT || $status === self::STATUS_PENDING;
    }

    /**
     * Valida estritamente assinatura em formato Data URI PNG (magic bytes + limite de 256 KB)
     * e re-codifica de forma canônica para eliminar qualquer carga arbitrária.
     */
    public static function validateSignatureDataUri(?string $dataUri): ?string
    {
        if (!is_string($dataUri)) {
            return null;
        }

        $trimmed = trim($dataUri);
        $prefixLen = strlen(self::PNG_DATA_URI_PREFIX);
        if (strlen($trimmed) <= $prefixLen) {
            return null;
        }

        if (strtolower(substr($trimmed, 0, $prefixLen)) !== self::PNG_DATA_URI_PREFIX) {
            return null;
        }

        $b64 = substr($trimmed, $prefixLen);
        if ($b64 === '' || !preg_match('/^[A-Za-z0-9+\/=]+$/', $b64)) {
            return null;
        }

        $binary = base64_decode($b64, true);
        if ($binary === false) {
            return null;
        }

        $len = strlen($binary);
        if ($len < 24 || $len > self::MAX_SIGNATURE_BYTES) {
            return null;
        }

        if (substr($binary, 0, 8) !== self::PNG_MAGIC) {
            return null;
        }

        if (substr($binary, 12, 4) !== 'IHDR') {
            return null;
        }

        return self::PNG_DATA_URI_PREFIX . base64_encode($binary);
    }

    /**
     * Converte string decimal (ex: "1.234,56", "19.99", "2,5") em inteiro com 2 casas fixas (centavos ou x100)
     * sem aritmética de ponto flutuante.
     */
    public static function parseDecimalToInt(string $raw, bool $allowNegative = false): int
    {
        $clean = trim($raw);
        if ($clean === '') {
            return 0;
        }

        $clean = preg_replace('/[^0-9.,\-]/', '', $clean);
        if ($clean === '' || $clean === '-') {
            return 0;
        }

        $negative = false;
        if (substr($clean, 0, 1) === '-') {
            $negative = true;
            $clean    = substr($clean, 1);
        }
        $clean = str_replace('-', '', $clean);

        if ($negative && !$allowNegative) {
            return 0;
        }

        $lastComma = strrpos($clean, ',');
        $lastDot   = strrpos($clean, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $intRaw  = str_replace(['.', ','], '', substr($clean, 0, $lastComma));
                $fracRaw = str_replace(['.', ','], '', substr($clean, $lastComma + 1));
            } else {
                $intRaw  = str_replace(['.', ','], '', substr($clean, 0, $lastDot));
                $fracRaw = str_replace(['.', ','], '', substr($clean, $lastDot + 1));
            }
        } elseif ($lastComma !== false) {
            if (substr_count($clean, ',') === 1) {
                $intRaw  = substr($clean, 0, $lastComma);
                $fracRaw = substr($clean, $lastComma + 1);
            } else {
                $intRaw  = str_replace(',', '', $clean);
                $fracRaw = '';
            }
        } elseif ($lastDot !== false) {
            if (substr_count($clean, '.') === 1) {
                $intRaw  = substr($clean, 0, $lastDot);
                $fracRaw = substr($clean, $lastDot + 1);
            } else {
                $intRaw  = str_replace('.', '', $clean);
                $fracRaw = '';
            }
        } else {
            $intRaw  = $clean;
            $fracRaw = '';
        }

        $intDigits  = preg_replace('/\D/', '', $intRaw);
        $fracDigits = preg_replace('/\D/', '', $fracRaw);

        $intVal     = $intDigits !== '' ? (int) $intDigits : 0;
        $fracPadded = str_pad($fracDigits, 3, '0', STR_PAD_RIGHT);
        $cents      = (int) substr($fracPadded, 0, 2);
        $thirdDigit = (int) substr($fracPadded, 2, 1);

        $total = $intVal * 100 + $cents;
        if ($thirdDigit >= 5) {
            $total++;
        }

        return $negative ? -$total : $total;
    }

    /**
     * Calcula o total em centavos de um item: round((quantity_x100 * unit_price_cents) / 100).
     */
    public static function computeItemTotalCents(int $quantityX100, int $unitPriceCents): int
    {
        $product = $quantityX100 * $unitPriceCents;
        if ($product >= 0) {
            return intdiv($product + 50, 100);
        }
        return -intdiv(abs($product) + 50, 100);
    }

    /**
     * Formata valor inteiro em centavos para exibição monetária legível.
     */
    public static function formatMoneyCents(int $cents, string $currencySymbol = 'R$'): string
    {
        $sign   = $cents < 0 ? '-' : '';
        $abs    = abs($cents);
        $major  = intdiv($abs, 100);
        $minor  = $abs % 100;
        $symbol = trim($currencySymbol) !== '' ? trim($currencySymbol) : 'R$';

        return $sign . $symbol . ' ' . number_format($major, 0, ',', '.') . ',' . str_pad((string) $minor, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Formata quantidade x100 em formato decimal de 2 casas.
     */
    public static function formatQuantity(int $quantityX100): string
    {
        $abs   = abs($quantityX100);
        $major = intdiv($abs, 100);
        $minor = $abs % 100;
        $sign  = $quantityX100 < 0 ? '-' : '';

        return $sign . $major . ',' . str_pad((string) $minor, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Formata centavos para valor de entrada em formulário (ex: "50,00").
     */
    public static function formatDecimalInput(int $valueX100): string
    {
        $abs   = abs($valueX100);
        $major = intdiv($abs, 100);
        $minor = $abs % 100;
        return $major . ',' . str_pad((string) $minor, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Gera o próximo número sequencial de OS no formato OS-YYYY-000001.
     */
    public static function generateNextOrderNumber(?int $year = null): string
    {
        global $DB;

        $targetYear = $year !== null ? $year : (int) gmdate('Y', self::nowTs());
        $prefix     = sprintf('OS-%04d-', $targetYear);
        $maxSeq     = 0;

        if (isset($DB) && is_object($DB)) {
            $iter = $DB->request([
                'SELECT' => ['order_number'],
                'FROM'   => self::TABLE_ORDERS,
                'WHERE'  => [
                    'order_number' => ['LIKE', $prefix . '%'],
                ],
                'ORDER'  => 'id DESC',
            ]);

            foreach ($iter as $row) {
                $num = (string) ($row['order_number'] ?? '');
                if (strpos($num, $prefix) === 0) {
                    $suffix = substr($num, strlen($prefix));
                    if (ctype_digit($suffix)) {
                        $seq = (int) $suffix;
                        if ($seq > $maxSeq) {
                            $maxSeq = $seq;
                        }
                    }
                }
            }
        }

        $nextSeq = $maxSeq + 1;
        return $prefix . str_pad((string) $nextSeq, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Cria uma nova Ordem de Serviço vinculada ao chamado.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public static function createOrder(array $data): ?array
    {
        global $DB;

        if (!isset($DB) || !is_object($DB)) {
            return null;
        }

        $ticketsId = (int) ($data['tickets_id'] ?? 0);
        if ($ticketsId <= 0) {
            return null;
        }

        $entitiesId        = max(0, (int) ($data['entities_id'] ?? 0));
        $technicianUsersId = max(0, (int) ($data['technician_users_id'] ?? 0));
        $travelCents       = max(0, (int) ($data['travel_cents'] ?? 0));
        $summary           = self::cleanSummary((string) ($data['summary'] ?? ''));

        $techSig = null;
        if (isset($data['tech_signature']) && trim((string) $data['tech_signature']) !== '') {
            $techSig = self::validateSignatureDataUri((string) $data['tech_signature']);
            if ($techSig === null) {
                return null;
            }
        }

        $nowStr      = gmdate('Y-m-d H:i:s', self::nowTs());
        $orderNumber = self::generateNextOrderNumber();

        $insertData = PluginOrderserviceCompat::dbValues([
            'order_number'        => $orderNumber,
            'tickets_id'          => $ticketsId,
            'entities_id'         => $entitiesId,
            'technician_users_id' => $technicianUsersId,
            'summary'             => $summary,
            'travel_cents'        => $travelCents,
            'subtotal_cents'      => 0,
            'total_cents'         => $travelCents,
            'status'              => self::STATUS_DRAFT,
            'token_hash'          => '',
            'token_expires_at'    => null,
            'tech_signature'      => $techSig,
            'client_signature'    => null,
            'signed_by_name'      => '',
            'signed_at'           => null,
            'date_creation'       => $nowStr,
            'date_mod'            => $nowStr,
        ]);

        if (!$DB->insert(self::TABLE_ORDERS, $insertData)) {
            return null;
        }

        return self::getByOrderNumber($orderNumber);
    }

    /**
     * Atualiza campos editáveis da OS (resumo, deslocamento, técnico, assinatura do técnico).
     *
     * @param array<string, mixed> $data
     */
    public static function updateOrder(int $orderId, array $data): bool
    {
        global $DB;

        $order = self::getById($orderId);
        if ($order === null || !self::isEditableStatus((string) ($order['status'] ?? ''))) {
            return false;
        }

        $update = [
            'date_mod' => gmdate('Y-m-d H:i:s', self::nowTs()),
        ];

        if (array_key_exists('summary', $data)) {
            $update['summary'] = self::cleanSummary((string) $data['summary']);
        }
        if (array_key_exists('technician_users_id', $data)) {
            $update['technician_users_id'] = max(0, (int) $data['technician_users_id']);
        }
        if (array_key_exists('travel_cents', $data)) {
            $update['travel_cents'] = max(0, (int) $data['travel_cents']);
        }
        if (array_key_exists('tech_signature', $data) && trim((string) $data['tech_signature']) !== '') {
            $validated = self::validateSignatureDataUri((string) $data['tech_signature']);
            if ($validated === null) {
                return false;
            }
            $update['tech_signature'] = $validated;
        }

        $ok = (bool) $DB->update(
            self::TABLE_ORDERS,
            PluginOrderserviceCompat::dbValues($update),
            ['id' => $orderId]
        );

        if ($ok) {
            self::recalculateOrderTotals($orderId);
        }

        return $ok;
    }

    /**
     * Cancela uma OS ainda não assinada e invalida qualquer token pendente.
     */
    public static function cancelOrder(int $orderId): bool
    {
        global $DB;

        $order = self::getById($orderId);
        if ($order === null || !self::isEditableStatus((string) ($order['status'] ?? ''))) {
            return false;
        }

        return (bool) $DB->update(
            self::TABLE_ORDERS,
            PluginOrderserviceCompat::dbValues([
                'status'           => self::STATUS_CANCELLED,
                'token_hash'       => '',
                'token_expires_at' => null,
                'date_mod'         => gmdate('Y-m-d H:i:s', self::nowTs()),
            ]),
            ['id' => $orderId]
        );
    }

    /**
     * Adiciona um item (serviço, material ou deslocamento) e recalcula totais da OS.
     */
    public static function addItem(
        int $orderId,
        string $itemType,
        string $description,
        int $quantityX100,
        int $unitPriceCents
    ): bool {
        global $DB;

        $order = self::getById($orderId);
        if ($order === null || !self::isEditableStatus((string) ($order['status'] ?? ''))) {
            return false;
        }

        if (!in_array($itemType, self::validItemTypes(), true)) {
            return false;
        }

        $cleanDesc = self::cleanLineText($description, 255);
        if ($cleanDesc === '' || $quantityX100 <= 0 || $unitPriceCents < 0) {
            return false;
        }

        $itemTotal = self::computeItemTotalCents($quantityX100, $unitPriceCents);

        $ok = (bool) $DB->insert(
            self::TABLE_ITEMS,
            PluginOrderserviceCompat::dbValues([
                'orders_id'        => $orderId,
                'item_type'        => $itemType,
                'description'      => $cleanDesc,
                'quantity_x100'    => $quantityX100,
                'unit_price_cents' => $unitPriceCents,
                'total_cents'      => $itemTotal,
                'date_creation'    => gmdate('Y-m-d H:i:s', self::nowTs()),
            ])
        );

        if ($ok) {
            self::recalculateOrderTotals($orderId);
        }

        return $ok;
    }

    /**
     * Remove um item pertencente à OS informada e recalcula os totais.
     */
    public static function deleteItem(int $orderId, int $itemId): bool
    {
        global $DB;

        if ($orderId <= 0 || $itemId <= 0) {
            return false;
        }

        $order = self::getById($orderId);
        if ($order === null || !self::isEditableStatus((string) ($order['status'] ?? ''))) {
            return false;
        }

        $ok = (bool) $DB->delete(self::TABLE_ITEMS, [
            'id'        => $itemId,
            'orders_id' => $orderId,
        ]);

        if ($ok) {
            self::recalculateOrderTotals($orderId);
        }

        return $ok;
    }

    /**
     * Recalcula subtotal_cents (soma dos itens) e total_cents (subtotal + travel_cents) em centavos inteiros.
     */
    public static function recalculateOrderTotals(int $orderId): bool
    {
        global $DB;

        $order = self::getById($orderId);
        if ($order === null) {
            return false;
        }

        $items         = self::getItemsForOrder($orderId);
        $subtotalCents = 0;
        foreach ($items as $item) {
            $subtotalCents += (int) ($item['total_cents'] ?? 0);
        }

        $travelCents = max(0, (int) ($order['travel_cents'] ?? 0));
        $totalCents  = $subtotalCents + $travelCents;

        return (bool) $DB->update(
            self::TABLE_ORDERS,
            [
                'subtotal_cents' => $subtotalCents,
                'total_cents'    => $totalCents,
                'date_mod'       => gmdate('Y-m-d H:i:s', self::nowTs()),
            ],
            ['id' => $orderId]
        );
    }

    /**
     * Gera token criptográfico efêmero para assinatura remota, armazenando apenas SHA-256 no banco.
     */
    public static function generateSignToken(int $orderId): ?string
    {
        global $DB;

        $order = self::getById($orderId);
        if ($order === null || !self::isEditableStatus((string) ($order['status'] ?? ''))) {
            return null;
        }

        $config    = PluginOrderserviceConfig::getConfig();
        $ttlHours  = max(1, (int) ($config['token_ttl_hours'] ?? 72));
        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $nowTs     = self::nowTs();
        $expiresAt = gmdate('Y-m-d H:i:s', $nowTs + ($ttlHours * 3600));

        $ok = (bool) $DB->update(
            self::TABLE_ORDERS,
            PluginOrderserviceCompat::dbValues([
                'status'           => self::STATUS_PENDING,
                'token_hash'       => $tokenHash,
                'token_expires_at' => $expiresAt,
                'date_mod'         => gmdate('Y-m-d H:i:s', $nowTs),
            ]),
            ['id' => $orderId]
        );

        return $ok ? $rawToken : null;
    }

    /**
     * Busca uma OS válida pelo token bruto (sem consumi-lo — seguro para requisições GET).
     *
     * @return array<string, mixed>|null
     */
    public static function findByToken(string $rawToken): ?array
    {
        global $DB;

        $token = trim($rawToken);
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
            return null;
        }

        $tokenHash = hash('sha256', strtolower($token));
        if (!isset($DB) || !is_object($DB)) {
            return null;
        }

        $iter = $DB->request([
            'FROM'  => self::TABLE_ORDERS,
            'WHERE' => ['token_hash' => $tokenHash],
            'LIMIT' => 1,
        ]);

        foreach ($iter as $row) {
            $status = (string) ($row['status'] ?? '');
            if (!self::isEditableStatus($status)) {
                return null;
            }
            $expiresRaw = (string) ($row['token_expires_at'] ?? '');
            if ($expiresRaw !== '') {
                $expiresTs = strtotime($expiresRaw . ' UTC');
                if ($expiresTs === false || $expiresTs < self::nowTs()) {
                    return null;
                }
            }
            return $row;
        }

        return null;
    }

    /**
     * Registra assinatura presencial (técnico autenticado no chamado) e invalida token pendente.
     */
    public static function signLocally(
        int $orderId,
        string $clientSignatureDataUri,
        string $signedByName,
        ?string $techSignatureDataUri = null
    ): bool {
        global $DB;

        $order = self::getById($orderId);
        if ($order === null || !self::isEditableStatus((string) ($order['status'] ?? ''))) {
            return false;
        }

        $cleanName = self::cleanLineText($signedByName, 255);
        $clientSig = self::validateSignatureDataUri($clientSignatureDataUri);
        if ($cleanName === '' || $clientSig === null) {
            return false;
        }

        $update = [
            'status'           => self::STATUS_SIGNED,
            'client_signature' => $clientSig,
            'signed_by_name'   => $cleanName,
            'signed_at'        => gmdate('Y-m-d H:i:s', self::nowTs()),
            'token_hash'       => '',
            'token_expires_at' => null,
            'date_mod'         => gmdate('Y-m-d H:i:s', self::nowTs()),
        ];

        if ($techSignatureDataUri !== null && trim($techSignatureDataUri) !== '') {
            $techSig = self::validateSignatureDataUri($techSignatureDataUri);
            if ($techSig === null) {
                return false;
            }
            $update['tech_signature'] = $techSig;
        }

        return (bool) $DB->update(
            self::TABLE_ORDERS,
            PluginOrderserviceCompat::dbValues($update),
            ['id' => $orderId]
        );
    }

    /**
     * Consome o token de assinatura remota (uso único) e finaliza a OS como assinada.
     *
     * @return array{ok: bool, error?: string, order_id?: int}
     */
    public static function signByToken(string $rawToken, string $clientSignatureDataUri, string $signedByName): array
    {
        global $DB;

        $order = self::findByToken($rawToken);
        if ($order === null) {
            return [
                'ok'    => false,
                'error' => __('Signature link is invalid, expired, or has already been used.', 'orderservice'),
            ];
        }

        $cleanName = self::cleanLineText($signedByName, 255);
        if ($cleanName === '') {
            return [
                'ok'    => false,
                'error' => __('Please provide the signer full name.', 'orderservice'),
            ];
        }

        $validatedSig = self::validateSignatureDataUri($clientSignatureDataUri);
        if ($validatedSig === null) {
            return [
                'ok'    => false,
                'error' => __('Please provide a valid PNG signature.', 'orderservice'),
            ];
        }

        $orderId   = (int) ($order['id'] ?? 0);
        $tokenHash = hash('sha256', strtolower(trim($rawToken)));
        $nowStr    = gmdate('Y-m-d H:i:s', self::nowTs());

        $ok = (bool) $DB->update(
            self::TABLE_ORDERS,
            PluginOrderserviceCompat::dbValues([
                'status'           => self::STATUS_SIGNED,
                'client_signature' => $validatedSig,
                'signed_by_name'   => $cleanName,
                'signed_at'        => $nowStr,
                'token_hash'       => '',
                'token_expires_at' => null,
                'date_mod'         => $nowStr,
            ]),
            [
                'id'         => $orderId,
                'token_hash' => $tokenHash,
            ]
        );

        if (!$ok || (method_exists($DB, 'affectedRows') && (int) $DB->affectedRows() <= 0)) {
            return [
                'ok'    => false,
                'error' => __('Signature link is invalid, expired, or has already been used.', 'orderservice'),
            ];
        }

        return [
            'ok'       => true,
            'order_id' => $orderId,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getById(int $orderId): ?array
    {
        global $DB;

        if ($orderId <= 0 || !isset($DB) || !is_object($DB)) {
            return null;
        }

        $iter = $DB->request([
            'FROM'  => self::TABLE_ORDERS,
            'WHERE' => ['id' => $orderId],
            'LIMIT' => 1,
        ]);

        foreach ($iter as $row) {
            return $row;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getByOrderNumber(string $orderNumber): ?array
    {
        global $DB;

        if ($orderNumber === '' || !isset($DB) || !is_object($DB)) {
            return null;
        }

        $iter = $DB->request([
            'FROM'  => self::TABLE_ORDERS,
            'WHERE' => ['order_number' => $orderNumber],
            'LIMIT' => 1,
        ]);

        foreach ($iter as $row) {
            return $row;
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getOrdersForTicket(int $ticketsId): array
    {
        global $DB;

        if ($ticketsId <= 0 || !isset($DB) || !is_object($DB)) {
            return [];
        }

        $iter = $DB->request([
            'FROM'  => self::TABLE_ORDERS,
            'WHERE' => ['tickets_id' => $ticketsId],
            'ORDER' => 'id DESC',
        ]);

        $rows = [];
        foreach ($iter as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    public static function countOrdersForTicket(int $ticketsId): int
    {
        global $DB;

        if ($ticketsId <= 0 || !isset($DB) || !is_object($DB)) {
            return 0;
        }

        $iter = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::TABLE_ORDERS,
            'WHERE'  => ['tickets_id' => $ticketsId],
        ]);

        $count = 0;
        foreach ($iter as $row) {
            $count++;
        }

        return $count;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getItemsForOrder(int $orderId): array
    {
        global $DB;

        if ($orderId <= 0 || !isset($DB) || !is_object($DB)) {
            return [];
        }

        $iter = $DB->request([
            'FROM'  => self::TABLE_ITEMS,
            'WHERE' => ['orders_id' => $orderId],
            'ORDER' => 'id ASC',
        ]);

        $rows = [];
        foreach ($iter as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Obtém nome legível de usuário no GLPI sem falha quando usuário não existir.
     */
    public static function getUserDisplayName(int $usersId): string
    {
        global $DB;

        if ($usersId <= 0 || !isset($DB) || !is_object($DB)) {
            return '-';
        }

        $iter = $DB->request([
            'SELECT' => ['id', 'name', 'realname', 'firstname'],
            'FROM'   => 'glpi_users',
            'WHERE'  => ['id' => $usersId],
            'LIMIT'  => 1,
        ]);

        foreach ($iter as $row) {
            $first = trim(PluginOrderserviceCompat::fromCore((string) ($row['firstname'] ?? '')));
            $last  = trim(PluginOrderserviceCompat::fromCore((string) ($row['realname'] ?? '')));
            $login = trim(PluginOrderserviceCompat::fromCore((string) ($row['name'] ?? '')));
            $full  = trim($first . ' ' . $last);
            if ($full !== '') {
                return $full;
            }
            if ($login !== '') {
                return $login;
            }
        }

        return sprintf(__('User #%d', 'orderservice'), $usersId);
    }

    /**
     * Obtém informações básicas do chamado vinculado para exibição em impressão/assinatura.
     *
     * @return array{id: int, name: string, entities_id: int}
     */
    public static function getTicketSummaryInfo(int $ticketsId): array
    {
        global $DB;

        $fallback = [
            'id'          => $ticketsId,
            'name'        => sprintf(__('Ticket #%d', 'orderservice'), $ticketsId),
            'entities_id' => 0,
        ];

        if ($ticketsId <= 0 || !isset($DB) || !is_object($DB)) {
            return $fallback;
        }

        $iter = $DB->request([
            'SELECT' => ['id', 'name', 'entities_id'],
            'FROM'   => 'glpi_tickets',
            'WHERE'  => ['id' => $ticketsId],
            'LIMIT'  => 1,
        ]);

        foreach ($iter as $row) {
            $name = trim(PluginOrderserviceCompat::fromCore((string) ($row['name'] ?? '')));
            return [
                'id'          => (int) ($row['id'] ?? $ticketsId),
                'name'        => $name !== '' ? $name : $fallback['name'],
                'entities_id' => (int) ($row['entities_id'] ?? 0),
            ];
        }

        return $fallback;
    }

    private static function cleanSummary(string $raw): string
    {
        $clean = trim(str_replace(["\r\n", "\r"], "\n", $raw));
        if (function_exists('mb_substr')) {
            return mb_substr($clean, 0, 5000, 'UTF-8');
        }
        return substr($clean, 0, 5000);
    }

    private static function cleanLineText(string $raw, int $maxLen): string
    {
        $clean = trim(preg_replace('/[\r\n\t]+/', ' ', $raw));
        if (function_exists('mb_substr')) {
            return mb_substr($clean, 0, $maxLen, 'UTF-8');
        }
        return substr($clean, 0, $maxLen);
    }
}
