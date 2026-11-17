<?php

/**
 * ------------------------------------------------------------------------
 * Order Service - Tela de impressão autorizada de Ordem de Serviço
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
    include_once __DIR__ . '/../../../inc/includes.php';
}

require_once __DIR__ . '/../setup.php';

if ((int) Session::getLoginUserID(true) <= 0) {
    PluginOrderserviceCompat::denyAccess();
    return;
}

$orderId = (int) ($_GET['id'] ?? 0);
$order   = PluginOrderserviceOrder::getById($orderId);
if ($order === null) {
    PluginOrderserviceCompat::denyAccess();
    return;
}

$ticketsId = (int) ($order['tickets_id'] ?? 0);
$ticket    = new Ticket();
if ($ticketsId <= 0 || !$ticket->getFromDB($ticketsId) || !$ticket->canViewItem()) {
    PluginOrderserviceCompat::denyAccess();
    return;
}

if (
    method_exists($ticket, 'getEntityID')
    && method_exists('Session', 'haveAccessToEntity')
    && !Session::haveAccessToEntity((int) $ticket->getEntityID())
) {
    PluginOrderserviceCompat::denyAccess();
    return;
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
}

$config      = PluginOrderserviceConfig::getConfig();
$currency    = (string) ($config['currency_symbol'] ?? 'R$');
$items       = PluginOrderserviceOrder::getItemsForOrder($orderId);
$ticketInfo  = PluginOrderserviceOrder::getTicketSummaryInfo($ticketsId);
$techName    = PluginOrderserviceOrder::getUserDisplayName((int) ($order['technician_users_id'] ?? 0));
$techSig     = PluginOrderserviceOrder::validateSignatureDataUri($order['tech_signature'] ?? null);
$clientSig   = PluginOrderserviceOrder::validateSignatureDataUri($order['client_signature'] ?? null);
$webBase     = PluginOrderserviceCompat::webPath(false);
$cssUrl      = $webBase . '/' . PluginOrderserviceCompat::assetPath('css/orderservice.css');
$jsUrl       = $webBase . '/' . PluginOrderserviceCompat::assetPath('js/orderservice.js');
$esc         = [PluginOrderserviceCompat::class, 'escapeHtml'];

echo '<!DOCTYPE html><html lang="' . $esc(PluginOrderserviceCompat::htmlLang()) . '"><head><meta charset="utf-8">';
echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
echo '<title>' . $esc((string) $order['order_number']) . ' - ' . $esc(__('Service Order', 'orderservice')) . '</title>';
echo '<link rel="stylesheet" href="' . $esc($cssUrl) . '">';
echo '</head><body class="orderservice-print-body">';

echo '<div class="orderservice-print-actions no-print">';
echo '<button type="button" class="orderservice-btn-primary os-print-trigger">' . $esc(__('Print Service Order', 'orderservice')) . '</button>';
echo '</div>';

echo '<article class="orderservice-print-sheet">';
echo '<header class="orderservice-doc-header">';
echo '<div>';
if (!empty($config['company_name'])) {
    echo '<h1>' . $esc((string) $config['company_name']) . '</h1>';
} else {
    echo '<h1>' . $esc(__('Service Order', 'orderservice')) . '</h1>';
}
if (!empty($config['company_document'])) {
    echo '<p class="orderservice-muted">' . $esc((string) $config['company_document']) . '</p>';
}
echo '</div>';
echo '<div class="orderservice-doc-number">';
echo '<strong>' . $esc((string) $order['order_number']) . '</strong>';
echo '<span>' . $esc(PluginOrderserviceOrder::getStatusLabel((string) $order['status'])) . '</span>';
echo '</div>';
echo '</header>';

$createdRaw = (string) ($order['date_creation'] ?? '');
$signedRaw  = (string) ($order['signed_at'] ?? '');
$createdFmt = $createdRaw !== ''
    ? ((class_exists('Html') && method_exists('Html', 'convDateTime')) ? (string) Html::convDateTime($createdRaw) : $createdRaw)
    : '-';
$signedFmt = $signedRaw !== ''
    ? ((class_exists('Html') && method_exists('Html', 'convDateTime')) ? (string) Html::convDateTime($signedRaw) : $signedRaw)
    : '-';

echo '<section class="orderservice-meta-grid">';
echo '<div><strong>' . $esc(__('Ticket', 'orderservice')) . ':</strong> #' . (int) $ticketInfo['id'] . ' - ' . $esc($ticketInfo['name']) . '</div>';
echo '<div><strong>' . $esc(__('Technician', 'orderservice')) . ':</strong> ' . $esc($techName) . '</div>';
echo '<div><strong>' . $esc(__('Created at', 'orderservice')) . ':</strong> ' . $esc($createdFmt) . '</div>';
echo '<div><strong>' . $esc(__('Signed at', 'orderservice')) . ':</strong> ' . $esc($signedFmt) . '</div>';
echo '</section>';

if ((string) ($order['summary'] ?? '') !== '') {
    echo '<section class="orderservice-section">';
    echo '<h3>' . $esc(__('Technical summary', 'orderservice')) . '</h3>';
    echo '<p class="orderservice-summary">' . nl2br($esc((string) $order['summary'])) . '</p>';
    echo '</section>';
}

echo '<section class="orderservice-section">';
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
echo '<tr><th colspan="4" class="num">' . $esc(__('Items Subtotal', 'orderservice')) . '</th><th class="num">' . $esc(PluginOrderserviceOrder::formatMoneyCents((int) $order['subtotal_cents'], $currency)) . '</th></tr>';
echo '<tr><th colspan="4" class="num">' . $esc(__('Travel fee', 'orderservice')) . '</th><th class="num">' . $esc(PluginOrderserviceOrder::formatMoneyCents((int) $order['travel_cents'], $currency)) . '</th></tr>';
echo '<tr><th colspan="4" class="num">' . $esc(__('Total', 'orderservice')) . '</th><th class="num">' . $esc(PluginOrderserviceOrder::formatMoneyCents((int) $order['total_cents'], $currency)) . '</th></tr>';
echo '</tfoot></table></section>';

echo '<section class="orderservice-signatures-grid">';
echo '<div class="orderservice-sig-box">';
echo '<div class="orderservice-sig-label">' . $esc(__('Technician signature', 'orderservice')) . '</div>';
if ($techSig !== null) {
    echo '<img class="orderservice-sig-img" alt="' . $esc(__('Technician signature', 'orderservice')) . '" src="' . $esc($techSig) . '">';
} else {
    echo '<div class="orderservice-sig-blank"></div>';
}
echo '<div class="orderservice-sig-name">' . $esc($techName) . '</div>';
echo '</div>';

echo '<div class="orderservice-sig-box">';
echo '<div class="orderservice-sig-label">' . $esc(__('Client signature', 'orderservice')) . '</div>';
if ($clientSig !== null) {
    echo '<img class="orderservice-sig-img" alt="' . $esc(__('Client signature', 'orderservice')) . '" src="' . $esc($clientSig) . '">';
} else {
    echo '<div class="orderservice-sig-blank"></div>';
}
echo '<div class="orderservice-sig-name">' . $esc((string) (($order['signed_by_name'] ?? '') !== '' ? $order['signed_by_name'] : '-')) . '</div>';
echo '</div>';
echo '</section>';

if (!empty($config['terms_footer'])) {
    echo '<footer class="orderservice-terms">' . nl2br($esc((string) $config['terms_footer'])) . '</footer>';
}

echo '</article>';
echo '<script src="' . $esc($jsUrl) . '"></script>';
echo '</body></html>';
