<?php

/**
 * ------------------------------------------------------------------------
 * Order Service - Página de assinatura remota em duas etapas (GET / POST)
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

$SECURITY_STRATEGY = 'no_check';

if (!defined('GLPI_ROOT')) {
    include_once __DIR__ . '/../../../inc/includes.php';
}

require_once __DIR__ . '/../setup.php';

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
}

$config = PluginOrderserviceConfig::getConfig();
if (empty($config['allow_public_sign']) && (int) Session::getLoginUserID(true) <= 0) {
    PluginOrderserviceCompat::denyAccess();
    return;
}

$webBase  = PluginOrderserviceCompat::webPath(false);
$cssUrl   = $webBase . '/' . PluginOrderserviceCompat::assetPath('css/orderservice.css');
$jsUrl    = $webBase . '/' . PluginOrderserviceCompat::assetPath('js/orderservice.js');
$postUrl  = $webBase . '/front/sign.php';
$esc      = [PluginOrderserviceCompat::class, 'escapeHtml'];
$currency = (string) ($config['currency_symbol'] ?? 'R$');

$method       = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$errorMessage = '';
$signedOrder  = null;
$rawToken     = '';

if ($method === 'POST') {
    if (!PluginOrderserviceCompat::requireSafePost()) {
        if (!headers_sent()) {
            http_response_code(403);
        }
        $errorMessage = __('Cross-origin request rejected.', 'orderservice');
    } else {
        $rawToken    = trim((string) ($_POST['sign_token'] ?? ''));
        $postedNonce = (string) ($_POST['sign_nonce'] ?? '');
        $sessNonce   = isset($_SESSION['plugin_orderservice_sign_nonce'])
            ? (string) $_SESSION['plugin_orderservice_sign_nonce']
            : '';

        if ($sessNonce === '' || $postedNonce === '' || !hash_equals($sessNonce, $postedNonce)) {
            if (!headers_sent()) {
                http_response_code(403);
            }
            $errorMessage = __('Invalid or expired form session. Please reload the link.', 'orderservice');
        } else {
            $signerName = PluginOrderserviceCompat::fromSanitizedInput((string) ($_POST['signed_by_name'] ?? ''));
            $clientSig  = (string) ($_POST['client_signature'] ?? '');
            $result     = PluginOrderserviceOrder::signByToken($rawToken, $clientSig, $signerName);

            if (!empty($result['ok']) && !empty($result['order_id'])) {
                unset($_SESSION['plugin_orderservice_sign_nonce']);
                $signedOrder = PluginOrderserviceOrder::getById((int) $result['order_id']);
            } else {
                $errorMessage = (string) ($result['error'] ?? __('Unable to complete signature.', 'orderservice'));
            }
        }
    }
} else {
    $rawToken = trim((string) ($_GET['t'] ?? ''));
}

$order = ($signedOrder === null && $rawToken !== '')
    ? PluginOrderserviceOrder::findByToken($rawToken)
    : null;

$signNonce = '';
if ($order !== null) {
    $signNonce = bin2hex(random_bytes(16));
    if (isset($_SESSION) && is_array($_SESSION)) {
        $_SESSION['plugin_orderservice_sign_nonce'] = $signNonce;
    }
}

echo '<!DOCTYPE html><html lang="' . $esc(PluginOrderserviceCompat::htmlLang()) . '"><head><meta charset="utf-8">';
echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
echo '<title>' . $esc(__('Remote Service Order Signature', 'orderservice')) . '</title>';
echo '<link rel="stylesheet" href="' . $esc($cssUrl) . '">';
echo '</head><body class="orderservice-standalone-body">';
echo '<main class="orderservice-standalone-shell">';

if (!empty($config['company_name']) || !empty($config['company_document'])) {
    echo '<header class="orderservice-doc-header">';
    if (!empty($config['company_name'])) {
        echo '<h1>' . $esc((string) $config['company_name']) . '</h1>';
    }
    if (!empty($config['company_document'])) {
        echo '<p class="orderservice-muted">' . $esc((string) $config['company_document']) . '</p>';
    }
    echo '</header>';
}

if ($signedOrder !== null) {
    $clientSig = PluginOrderserviceOrder::validateSignatureDataUri($signedOrder['client_signature'] ?? null);
    echo '<section class="orderservice-card orderservice-alert-success">';
    echo '<h2>' . $esc(__('Service Order signed successfully', 'orderservice')) . '</h2>';
    echo '<p>' . $esc(sprintf(__('Order %s has been signed by %s.', 'orderservice'), (string) $signedOrder['order_number'], (string) $signedOrder['signed_by_name'])) . '</p>';
    if ($clientSig !== null) {
        echo '<div class="orderservice-sig-preview"><img class="orderservice-sig-img" alt="' . $esc(__('Client signature', 'orderservice')) . '" src="' . $esc($clientSig) . '"></div>';
    }
    echo '</section>';
} elseif ($order === null) {
    $msg = $errorMessage !== ''
        ? $errorMessage
        : __('Signature link is invalid, expired, or has already been used.', 'orderservice');
    echo '<section class="orderservice-card orderservice-alert-error">';
    echo '<h2>' . $esc(__('Signature Unavailable', 'orderservice')) . '</h2>';
    echo '<p>' . $esc($msg) . '</p>';
    echo '</section>';
} else {
    $orderId     = (int) ($order['id'] ?? 0);
    $items       = PluginOrderserviceOrder::getItemsForOrder($orderId);
    $ticketInfo  = PluginOrderserviceOrder::getTicketSummaryInfo((int) ($order['tickets_id'] ?? 0));
    $techName    = PluginOrderserviceOrder::getUserDisplayName((int) ($order['technician_users_id'] ?? 0));
    $travelCents = (int) ($order['travel_cents'] ?? 0);
    $subCents    = (int) ($order['subtotal_cents'] ?? 0);
    $totalCents  = (int) ($order['total_cents'] ?? 0);

    if ($errorMessage !== '') {
        echo '<div class="orderservice-card orderservice-alert-error"><p>' . $esc($errorMessage) . '</p></div>';
    }

    echo '<section class="orderservice-card">';
    echo '<div class="orderservice-meta-grid">';
    echo '<div><strong>' . $esc(__('Service Order', 'orderservice')) . ':</strong> ' . $esc((string) $order['order_number']) . '</div>';
    echo '<div><strong>' . $esc(__('Ticket', 'orderservice')) . ':</strong> #' . (int) $ticketInfo['id'] . ' - ' . $esc($ticketInfo['name']) . '</div>';
    echo '<div><strong>' . $esc(__('Technician', 'orderservice')) . ':</strong> ' . $esc($techName) . '</div>';
    echo '<div><strong>' . $esc(__('Total', 'orderservice')) . ':</strong> ' . $esc(PluginOrderserviceOrder::formatMoneyCents($totalCents, $currency)) . '</div>';
    echo '</div>';

    if ((string) ($order['summary'] ?? '') !== '') {
        echo '<div class="orderservice-section">';
        echo '<h3>' . $esc(__('Technical summary', 'orderservice')) . '</h3>';
        echo '<p class="orderservice-summary">' . nl2br($esc((string) $order['summary'])) . '</p>';
        echo '</div>';
    }

    echo '<div class="orderservice-section">';
    echo '<h3>' . $esc(__('Items and Services', 'orderservice')) . '</h3>';
    echo '<table class="orderservice-table">';
    echo '<thead><tr>';
    echo '<th>' . $esc(__('Type', 'orderservice')) . '</th>';
    echo '<th>' . $esc(__('Description', 'orderservice')) . '</th>';
    echo '<th class="num">' . $esc(__('Qty', 'orderservice')) . '</th>';
    echo '<th class="num">' . $esc(__('Unit Price', 'orderservice')) . '</th>';
    echo '<th class="num">' . $esc(__('Subtotal', 'orderservice')) . '</th>';
    echo '</tr></thead><tbody>';

    if ($items === []) {
        echo '<tr><td colspan="5">' . $esc(__('No items registered.', 'orderservice')) . '</td></tr>';
    } else {
        foreach ($items as $item) {
            echo '<tr>';
            echo '<td>' . $esc(PluginOrderserviceOrder::getItemTypeLabel((string) $item['item_type'])) . '</td>';
            echo '<td>' . $esc((string) $item['description']) . '</td>';
            echo '<td class="num">' . $esc(PluginOrderserviceOrder::formatQuantity((int) $item['quantity_x100'])) . '</td>';
            echo '<td class="num">' . $esc(PluginOrderserviceOrder::formatMoneyCents((int) $item['unit_price_cents'], $currency)) . '</td>';
            echo '<td class="num">' . $esc(PluginOrderserviceOrder::formatMoneyCents((int) $item['total_cents'], $currency)) . '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody><tfoot>';
    echo '<tr><th colspan="4" class="num">' . $esc(__('Items Subtotal', 'orderservice')) . '</th><th class="num">' . $esc(PluginOrderserviceOrder::formatMoneyCents($subCents, $currency)) . '</th></tr>';
    echo '<tr><th colspan="4" class="num">' . $esc(__('Travel fee', 'orderservice')) . '</th><th class="num">' . $esc(PluginOrderserviceOrder::formatMoneyCents($travelCents, $currency)) . '</th></tr>';
    echo '<tr><th colspan="4" class="num">' . $esc(__('Total', 'orderservice')) . '</th><th class="num">' . $esc(PluginOrderserviceOrder::formatMoneyCents($totalCents, $currency)) . '</th></tr>';
    echo '</tfoot></table></div>';

    if (!empty($config['terms_footer'])) {
        echo '<div class="orderservice-terms">' . nl2br($esc((string) $config['terms_footer'])) . '</div>';
    }

    echo '<form method="post" action="' . $esc($postUrl) . '" class="orderservice-sign-form os-remote-sign-form">';
    echo '<input type="hidden" name="sign_token" value="' . $esc($rawToken) . '">';
    echo '<input type="hidden" name="sign_nonce" value="' . $esc($signNonce) . '">';

    echo '<div class="orderservice-field">';
    echo '<label for="os-remote-signer-name">' . $esc(__('Signer full name', 'orderservice')) . '</label>';
    echo '<input id="os-remote-signer-name" type="text" name="signed_by_name" required maxlength="255" class="orderservice-input">';
    echo '</div>';

    $statusOk    = __('Signature captured', 'orderservice');
    $statusEmpty = __('Draw signature above', 'orderservice');
    $msgRequired = __('Signature required', 'orderservice');

    echo '<div class="orderservice-field">';
    echo '<label>' . $esc(__('Client signature', 'orderservice')) . '</label>';
    echo '<div class="os-signature-pad" data-input-id="os-remote-client-sig" data-required="1"'
        . ' data-status-ok="' . $esc($statusOk) . '"'
        . ' data-status-empty="' . $esc($statusEmpty) . '"'
        . ' data-msg-required="' . $esc($msgRequired) . '">';
    echo '<canvas class="os-signature-canvas" width="460" height="160"></canvas>';
    echo '<input type="hidden" id="os-remote-client-sig" name="client_signature" value="">';
    echo '<div class="os-signature-toolbar">';
    echo '<span class="os-signature-status">' . $esc($statusEmpty) . '</span>';
    echo '<button type="button" class="orderservice-btn-secondary os-clear-sig">' . $esc(__('Clear', 'orderservice')) . '</button>';
    echo '</div></div></div>';

    echo '<button type="submit" class="orderservice-btn-primary">' . $esc(__('Confirm and Sign Service Order', 'orderservice')) . '</button>';
    Html::closeForm();
    echo '</section>';
}

echo '</main>';
echo '<script src="' . $esc($jsUrl) . '"></script>';
echo '</body></html>';
