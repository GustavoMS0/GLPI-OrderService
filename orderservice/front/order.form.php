<?php

/**
 * ------------------------------------------------------------------------
 * Order Service - Endpoint autenticado para operações em Ordens de Serviço
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

if (!PluginOrderserviceCompat::requireSafePost()) {
    PluginOrderserviceCompat::denyAccess();
    return;
}

$userId = (int) Session::getLoginUserID(true);
if ($userId <= 0) {
    PluginOrderserviceCompat::denyAccess();
    return;
}

$ticketsId = (int) ($_POST['tickets_id'] ?? 0);
$ticket    = new Ticket();
if ($ticketsId <= 0 || !$ticket->getFromDB($ticketsId)) {
    PluginOrderserviceCompat::denyAccess();
    return;
}

if (
    !$ticket->canViewItem()
    || !Session::haveRight('ticket', UPDATE)
    || (method_exists($ticket, 'canUpdateItem') && !$ticket->canUpdateItem())
) {
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

$action  = trim((string) ($_POST['os_action'] ?? ''));
$infoLvl = defined('INFO') ? INFO : 0;
$errLvl  = defined('ERROR') ? ERROR : 3;

if ($action === 'create_order') {
    $summary   = PluginOrderserviceCompat::fromSanitizedInput((string) ($_POST['summary'] ?? ''));
    $travelRaw = PluginOrderserviceCompat::fromSanitizedInput((string) ($_POST['travel_amount'] ?? '0'));
    $techSig   = trim((string) ($_POST['tech_signature'] ?? ''));

    if ($techSig !== '' && PluginOrderserviceOrder::validateSignatureDataUri($techSig) === null) {
        Session::addMessageAfterRedirect(__('Invalid technician PNG signature payload.', 'orderservice'), false, $errLvl);
        Html::back();
        return;
    }

    $created = PluginOrderserviceOrder::createOrder([
        'tickets_id'          => $ticketsId,
        'entities_id'         => method_exists($ticket, 'getEntityID') ? (int) $ticket->getEntityID() : 0,
        'technician_users_id' => $userId,
        'summary'             => $summary,
        'travel_cents'        => PluginOrderserviceOrder::parseDecimalToInt($travelRaw),
        'tech_signature'      => $techSig,
    ]);

    if ($created !== null) {
        Session::addMessageAfterRedirect(
            sprintf(__('Service Order %s created successfully.', 'orderservice'), (string) $created['order_number']),
            false,
            $infoLvl
        );
    } else {
        Session::addMessageAfterRedirect(__('Could not create Service Order.', 'orderservice'), false, $errLvl);
    }

    Html::back();
    return;
}

$orderId = (int) ($_POST['orders_id'] ?? 0);
$order   = PluginOrderserviceOrder::getById($orderId);
if ($order === null || (int) ($order['tickets_id'] ?? 0) !== $ticketsId) {
    PluginOrderserviceCompat::denyAccess();
    return;
}

switch ($action) {
    case 'update_order':
        $summary   = PluginOrderserviceCompat::fromSanitizedInput((string) ($_POST['summary'] ?? ''));
        $travelRaw = PluginOrderserviceCompat::fromSanitizedInput((string) ($_POST['travel_amount'] ?? '0'));

        $ok = PluginOrderserviceOrder::updateOrder($orderId, [
            'summary'      => $summary,
            'travel_cents' => PluginOrderserviceOrder::parseDecimalToInt($travelRaw),
        ]);
        Session::addMessageAfterRedirect(
            $ok ? __('Service Order updated successfully.', 'orderservice') : __('Could not update Service Order.', 'orderservice'),
            false,
            $ok ? $infoLvl : $errLvl
        );
        break;

    case 'add_item':
        $itemType    = trim((string) ($_POST['item_type'] ?? 'service'));
        $description = PluginOrderserviceCompat::fromSanitizedInput((string) ($_POST['description'] ?? ''));
        $qtyRaw      = PluginOrderserviceCompat::fromSanitizedInput((string) ($_POST['quantity'] ?? '1'));
        $unitRaw     = PluginOrderserviceCompat::fromSanitizedInput((string) ($_POST['unit_price'] ?? '0'));

        $qtyX100        = PluginOrderserviceOrder::parseDecimalToInt($qtyRaw);
        $unitPriceCents = PluginOrderserviceOrder::parseDecimalToInt($unitRaw);

        $ok = PluginOrderserviceOrder::addItem($orderId, $itemType, $description, $qtyX100, $unitPriceCents);
        Session::addMessageAfterRedirect(
            $ok ? __('Item added to Service Order.', 'orderservice') : __('Could not add item. Check description and amounts.', 'orderservice'),
            false,
            $ok ? $infoLvl : $errLvl
        );
        break;

    case 'delete_item':
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $ok     = PluginOrderserviceOrder::deleteItem($orderId, $itemId);
        Session::addMessageAfterRedirect(
            $ok ? __('Item removed from Service Order.', 'orderservice') : __('Could not remove item.', 'orderservice'),
            false,
            $ok ? $infoLvl : $errLvl
        );
        break;

    case 'generate_token':
        $rawToken = PluginOrderserviceOrder::generateSignToken($orderId);
        if ($rawToken !== null) {
            if (!isset($_SESSION['plugin_orderservice_last_sign']) || !is_array($_SESSION['plugin_orderservice_last_sign'])) {
                $_SESSION['plugin_orderservice_last_sign'] = [];
            }
            $_SESSION['plugin_orderservice_last_sign'][$ticketsId] = [
                'order_number' => (string) ($order['order_number'] ?? ''),
                'url'          => PluginOrderserviceCompat::webPath(true) . '/front/sign.php?t=' . $rawToken,
            ];
            Session::addMessageAfterRedirect(__('One-time remote signature link generated.', 'orderservice'), false, $infoLvl);
        } else {
            Session::addMessageAfterRedirect(__('Could not generate remote signature link.', 'orderservice'), false, $errLvl);
        }
        break;

    case 'sign_local':
        $signedByName = PluginOrderserviceCompat::fromSanitizedInput((string) ($_POST['signed_by_name'] ?? ''));
        $clientSig    = (string) ($_POST['client_signature'] ?? '');
        $techSig      = isset($_POST['tech_signature']) ? (string) $_POST['tech_signature'] : null;

        $ok = PluginOrderserviceOrder::signLocally($orderId, $clientSig, $signedByName, $techSig);
        Session::addMessageAfterRedirect(
            $ok ? __('Service Order signed successfully.', 'orderservice') : __('Invalid signer name or PNG signature payload.', 'orderservice'),
            false,
            $ok ? $infoLvl : $errLvl
        );
        break;

    case 'cancel_order':
        $ok = PluginOrderserviceOrder::cancelOrder($orderId);
        Session::addMessageAfterRedirect(
            $ok ? __('Service Order cancelled.', 'orderservice') : __('Could not cancel Service Order.', 'orderservice'),
            false,
            $ok ? $infoLvl : $errLvl
        );
        break;

    default:
        PluginOrderserviceCompat::denyAccess();
        return;
}

Html::back();
return;
