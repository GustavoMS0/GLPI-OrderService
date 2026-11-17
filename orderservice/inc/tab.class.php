<?php

/**
 * ------------------------------------------------------------------------
 * Order Service - Aba "Ordens de Serviço" exibida nos chamados (Ticket)
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

require_once __DIR__ . '/compat.class.php';
require_once __DIR__ . '/config.class.php';
require_once __DIR__ . '/order.class.php';

interface PluginOrderserviceTabConstants
{
    public const TAB_NUM = 1;
}

trait PluginOrderserviceTabBody
{
    public static function getTypeName($nb = 0): string
    {
        return __('Service Orders', 'orderservice');
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::$rightname, READ);
    }

    private static function canAccessTicket(CommonGLPI $item, $withtemplate = 0): bool
    {
        if ($withtemplate || !($item instanceof Ticket)) {
            return false;
        }

        $ticketsId = (int) $item->getID();
        if ($ticketsId <= 0) {
            return false;
        }

        if ((int) Session::getLoginUserID(true) <= 0) {
            return false;
        }

        if (!method_exists($item, 'canViewItem') || !$item->canViewItem()) {
            return false;
        }

        if (
            method_exists($item, 'getEntityID')
            && method_exists('Session', 'haveAccessToEntity')
            && !Session::haveAccessToEntity((int) $item->getEntityID())
        ) {
            return false;
        }

        return true;
    }

    private static function canManageTicket(CommonGLPI $item): bool
    {
        if (!Session::haveRight('ticket', UPDATE)) {
            return false;
        }
        if (method_exists($item, 'canUpdateItem') && !$item->canUpdateItem()) {
            return false;
        }
        return true;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!self::canAccessTicket($item, $withtemplate)) {
            return '';
        }

        $count = PluginOrderserviceOrder::countOrdersForTicket((int) $item->getID());
        return self::createTabEntry(__('Service Orders', 'orderservice'), $count);
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!self::canAccessTicket($item, $withtemplate)) {
            return false;
        }

        $ticketsId = (int) $item->getID();
        $canManage = self::canManageTicket($item);
        $config    = PluginOrderserviceConfig::getConfig();
        $currency  = (string) ($config['currency_symbol'] ?? 'R$');
        $orders    = PluginOrderserviceOrder::getOrdersForTicket($ticketsId);
        $webBase   = PluginOrderserviceCompat::webPath(false);
        $formUrl   = $webBase . '/front/order.form.php';
        $printBase = $webBase . '/front/print.php';
        $esc       = [PluginOrderserviceCompat::class, 'escapeHtml'];

        echo '<div class="orderservice-tab">';

        if (
            isset($_SESSION['plugin_orderservice_last_sign'])
            && is_array($_SESSION['plugin_orderservice_last_sign'])
            && isset($_SESSION['plugin_orderservice_last_sign'][$ticketsId])
        ) {
            $flash = $_SESSION['plugin_orderservice_last_sign'][$ticketsId];
            unset($_SESSION['plugin_orderservice_last_sign'][$ticketsId]);
            if (is_array($flash) && !empty($flash['url'])) {
                $flashNum = (string) ($flash['order_number'] ?? '');
                $flashUrl = (string) ($flash['url'] ?? '');
                echo '<div class="alert alert-success orderservice-token-flash">';
                echo '<strong>' . $esc(sprintf(__('Remote signature link for %s (shown once):', 'orderservice'), $flashNum)) . '</strong>';
                echo '<div class="mt-2"><input type="text" class="form-control form-control-sm os-readonly-link" readonly value="' . $esc($flashUrl) . '"></div>';
                echo '</div>';
            }
        }

        if ($canManage) {
            $defaultTravel = PluginOrderserviceOrder::formatDecimalInput((int) ($config['default_travel_cents'] ?? 0));
            echo '<div class="card mb-3 orderservice-create-card">';
            echo '<div class="card-header"><h3 class="card-title mb-0">' . $esc(__('New Service Order', 'orderservice')) . '</h3></div>';
            echo '<div class="card-body">';
            echo '<form method="post" action="' . $esc($formUrl) . '" class="os-order-create-form">';
            echo '<input type="hidden" name="tickets_id" value="' . $ticketsId . '">';
            echo '<input type="hidden" name="os_action" value="create_order">';

            echo '<div class="row g-3">';
            echo '<div class="col-md-8">';
            echo '<label class="form-label">' . $esc(__('Technical summary', 'orderservice')) . '</label>';
            echo '<textarea name="summary" rows="3" class="form-control" placeholder="' . $esc(__('Describe diagnosis, executed services, and observations...', 'orderservice')) . '"></textarea>';
            echo '</div>';
            echo '<div class="col-md-4">';
            echo '<label class="form-label">' . $esc(sprintf(__('Travel fee (%s)', 'orderservice'), $currency)) . '</label>';
            echo '<input type="text" name="travel_amount" class="form-control" value="' . $esc($defaultTravel) . '">';
            echo '</div>';
            echo '</div>';

            $newTechInputId = 'os-new-tech-sig-' . $ticketsId;
            echo '<div class="mt-3">';
            echo '<label class="form-label">' . $esc(__('Technician signature (optional)', 'orderservice')) . '</label>';
            self::renderSignaturePad($newTechInputId, 'tech_signature');
            echo '</div>';

            echo '<div class="mt-3">';
            echo '<button type="submit" class="btn btn-primary">' . $esc(__('Generate Service Order', 'orderservice')) . '</button>';
            echo '</div>';
            Html::closeForm();
            echo '</div></div>';
        }

        if ($orders === []) {
            echo '<div class="alert alert-info mb-0">' . $esc(__('No service orders have been created for this ticket yet.', 'orderservice')) . '</div>';
            echo '</div>';
            return true;
        }

        foreach ($orders as $order) {
            $orderId       = (int) ($order['id'] ?? 0);
            $orderNumber   = (string) ($order['order_number'] ?? '');
            $status        = (string) ($order['status'] ?? PluginOrderserviceOrder::STATUS_DRAFT);
            $editable      = $canManage && PluginOrderserviceOrder::isEditableStatus($status);
            $items         = PluginOrderserviceOrder::getItemsForOrder($orderId);
            $techUserId    = (int) ($order['technician_users_id'] ?? 0);
            $techName      = $techUserId > 0 ? PluginOrderserviceOrder::getUserDisplayName($techUserId) : '-';
            $travelCents   = (int) ($order['travel_cents'] ?? 0);
            $subtotalCents = (int) ($order['subtotal_cents'] ?? 0);
            $totalCents    = (int) ($order['total_cents'] ?? 0);
            $summary       = (string) ($order['summary'] ?? '');
            $techSig       = PluginOrderserviceOrder::validateSignatureDataUri($order['tech_signature'] ?? null);
            $clientSig     = PluginOrderserviceOrder::validateSignatureDataUri($order['client_signature'] ?? null);
            $signedByName  = (string) ($order['signed_by_name'] ?? '');
            $signedAt      = (string) ($order['signed_at'] ?? '');
            $createdAt     = (string) ($order['date_creation'] ?? '');

            $badgeClass = 'bg-secondary-lt';
            if ($status === PluginOrderserviceOrder::STATUS_SIGNED) {
                $badgeClass = 'bg-green-lt';
            } elseif ($status === PluginOrderserviceOrder::STATUS_PENDING) {
                $badgeClass = 'bg-yellow-lt';
            } elseif ($status === PluginOrderserviceOrder::STATUS_CANCELLED) {
                $badgeClass = 'bg-red-lt';
            }

            echo '<div class="card mb-3 orderservice-order-card">';
            echo '<div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">';
            echo '<div>';
            echo '<strong class="me-2">' . $esc($orderNumber) . '</strong>';
            echo '<span class="badge ' . $badgeClass . ' me-2">' . $esc(PluginOrderserviceOrder::getStatusLabel($status)) . '</span>';
            echo '<span class="text-muted small">' . $esc(__('Technician', 'orderservice')) . ': ' . $esc($techName);
            if ($createdAt !== '') {
                echo ' | ' . $esc((string) Html::convDateTime($createdAt));
            }
            echo '</span>';
            echo '</div>';
            echo '<div class="d-flex align-items-center gap-2">';
            echo '<span class="badge bg-blue-lt">' . $esc(PluginOrderserviceOrder::formatMoneyCents($totalCents, $currency)) . '</span>';
            echo '<a class="btn btn-sm btn-outline-secondary" href="' . $esc($printBase . '?id=' . $orderId) . '" target="_blank" rel="noopener">'
                . $esc(__('Print', 'orderservice'))
                . '</a>';
            echo '</div>';
            echo '</div>';

            echo '<div class="card-body">';

            if ($editable) {
                echo '<form method="post" action="' . $esc($formUrl) . '" class="mb-3">';
                echo '<input type="hidden" name="tickets_id" value="' . $ticketsId . '">';
                echo '<input type="hidden" name="orders_id" value="' . $orderId . '">';
                echo '<input type="hidden" name="os_action" value="update_order">';
                echo '<div class="row g-2 align-items-end">';
                echo '<div class="col-md-8">';
                echo '<label class="form-label">' . $esc(__('Technical summary', 'orderservice')) . '</label>';
                echo '<textarea name="summary" rows="2" class="form-control">' . $esc($summary) . '</textarea>';
                echo '</div>';
                echo '<div class="col-md-2">';
                echo '<label class="form-label">' . $esc(__('Travel fee', 'orderservice')) . '</label>';
                echo '<input type="text" name="travel_amount" class="form-control" value="' . $esc(PluginOrderserviceOrder::formatDecimalInput($travelCents)) . '">';
                echo '</div>';
                echo '<div class="col-md-2">';
                echo '<button type="submit" class="btn btn-outline-primary w-100">' . $esc(__('Update OS', 'orderservice')) . '</button>';
                echo '</div>';
                echo '</div>';
                Html::closeForm();
            } elseif ($summary !== '') {
                echo '<p class="mb-3 orderservice-summary">' . nl2br($esc($summary)) . '</p>';
            }

            echo '<div class="table-responsive mb-3">';
            echo '<table class="table table-sm table-striped table-bordered mb-0 orderservice-items-table">';
            echo '<thead><tr>';
            echo '<th>' . $esc(__('Type', 'orderservice')) . '</th>';
            echo '<th>' . $esc(__('Description', 'orderservice')) . '</th>';
            echo '<th class="text-end">' . $esc(__('Qty', 'orderservice')) . '</th>';
            echo '<th class="text-end">' . $esc(__('Unit Price', 'orderservice')) . '</th>';
            echo '<th class="text-end">' . $esc(__('Subtotal', 'orderservice')) . '</th>';
            if ($editable) {
                echo '<th class="text-center">' . $esc(__('Actions', 'orderservice')) . '</th>';
            }
            echo '</tr></thead><tbody>';

            if ($items === []) {
                $colspan = $editable ? 6 : 5;
                echo '<tr><td colspan="' . $colspan . '" class="text-center text-muted">'
                    . $esc(__('No items added yet.', 'orderservice'))
                    . '</td></tr>';
            } else {
                foreach ($items as $itemRow) {
                    $itemId    = (int) ($itemRow['id'] ?? 0);
                    $itemType  = (string) ($itemRow['item_type'] ?? PluginOrderserviceOrder::TYPE_SERVICE);
                    $itemDesc  = (string) ($itemRow['description'] ?? '');
                    $qtyX100   = (int) ($itemRow['quantity_x100'] ?? 0);
                    $unitCents = (int) ($itemRow['unit_price_cents'] ?? 0);
                    $lineTotal = (int) ($itemRow['total_cents'] ?? 0);

                    echo '<tr>';
                    echo '<td>' . $esc(PluginOrderserviceOrder::getItemTypeLabel($itemType)) . '</td>';
                    echo '<td>' . $esc($itemDesc) . '</td>';
                    echo '<td class="text-end">' . $esc(PluginOrderserviceOrder::formatQuantity($qtyX100)) . '</td>';
                    echo '<td class="text-end">' . $esc(PluginOrderserviceOrder::formatMoneyCents($unitCents, $currency)) . '</td>';
                    echo '<td class="text-end">' . $esc(PluginOrderserviceOrder::formatMoneyCents($lineTotal, $currency)) . '</td>';
                    if ($editable) {
                        echo '<td class="text-center">';
                        echo '<form method="post" action="' . $esc($formUrl) . '" class="d-inline">';
                        echo '<input type="hidden" name="tickets_id" value="' . $ticketsId . '">';
                        echo '<input type="hidden" name="orders_id" value="' . $orderId . '">';
                        echo '<input type="hidden" name="item_id" value="' . $itemId . '">';
                        echo '<input type="hidden" name="os_action" value="delete_item">';
                        echo '<button type="submit" class="btn btn-sm btn-outline-danger">' . $esc(__('Remove', 'orderservice')) . '</button>';
                        Html::closeForm();
                        echo '</td>';
                    }
                    echo '</tr>';
                }
            }

            echo '</tbody>';
            echo '<tfoot>';
            echo '<tr><th colspan="4" class="text-end">' . $esc(__('Items Subtotal', 'orderservice')) . '</th><th class="text-end">'
                . $esc(PluginOrderserviceOrder::formatMoneyCents($subtotalCents, $currency)) . '</th>' . ($editable ? '<th></th>' : '') . '</tr>';
            echo '<tr><th colspan="4" class="text-end">' . $esc(__('Travel fee', 'orderservice')) . '</th><th class="text-end">'
                . $esc(PluginOrderserviceOrder::formatMoneyCents($travelCents, $currency)) . '</th>' . ($editable ? '<th></th>' : '') . '</tr>';
            echo '<tr><th colspan="4" class="text-end">' . $esc(__('Total', 'orderservice')) . '</th><th class="text-end">'
                . $esc(PluginOrderserviceOrder::formatMoneyCents($totalCents, $currency)) . '</th>' . ($editable ? '<th></th>' : '') . '</tr>';
            echo '</tfoot></table></div>';

            if ($editable) {
                echo '<form method="post" action="' . $esc($formUrl) . '" class="row g-2 align-items-end mb-3 os-add-item-form">';
                echo '<input type="hidden" name="tickets_id" value="' . $ticketsId . '">';
                echo '<input type="hidden" name="orders_id" value="' . $orderId . '">';
                echo '<input type="hidden" name="os_action" value="add_item">';
                echo '<div class="col-md-2">';
                echo '<label class="form-label">' . $esc(__('Type', 'orderservice')) . '</label>';
                echo '<select name="item_type" class="form-select">';
                echo '<option value="service">' . $esc(__('Service', 'orderservice')) . '</option>';
                echo '<option value="material">' . $esc(__('Material', 'orderservice')) . '</option>';
                echo '<option value="travel">' . $esc(__('Travel', 'orderservice')) . '</option>';
                echo '</select>';
                echo '</div>';
                echo '<div class="col-md-4">';
                echo '<label class="form-label">' . $esc(__('Description', 'orderservice')) . '</label>';
                echo '<input type="text" name="description" class="form-control" required maxlength="255">';
                echo '</div>';
                echo '<div class="col-md-2">';
                echo '<label class="form-label">' . $esc(__('Qty', 'orderservice')) . '</label>';
                echo '<input type="text" name="quantity" class="form-control os-calc-qty" value="1,00" required>';
                echo '</div>';
                echo '<div class="col-md-2">';
                echo '<label class="form-label">' . $esc(__('Unit Price', 'orderservice')) . '</label>';
                echo '<input type="text" name="unit_price" class="form-control os-calc-unit" value="0,00" required>';
                echo '</div>';
                echo '<div class="col-md-2">';
                echo '<div class="small text-muted mb-1 os-calc-preview" data-currency="' . $esc($currency) . '"></div>';
                echo '<button type="submit" class="btn btn-secondary w-100">' . $esc(__('Add Item', 'orderservice')) . '</button>';
                echo '</div>';
                Html::closeForm();
            }

            if ($status === PluginOrderserviceOrder::STATUS_SIGNED) {
                echo '<div class="row g-3 mt-1 orderservice-signatures-view">';
                if ($techSig !== null) {
                    echo '<div class="col-md-6">';
                    echo '<div class="border rounded p-2 text-center">';
                    echo '<div class="small text-muted mb-1">' . $esc(__('Technician signature', 'orderservice')) . '</div>';
                    echo '<img class="orderservice-sig-img" alt="' . $esc(__('Technician signature', 'orderservice')) . '" src="' . $esc($techSig) . '">';
                    echo '<div class="small mt-1">' . $esc($techName) . '</div>';
                    echo '</div></div>';
                }
                if ($clientSig !== null) {
                    echo '<div class="col-md-6">';
                    echo '<div class="border rounded p-2 text-center">';
                    echo '<div class="small text-muted mb-1">' . $esc(__('Client signature', 'orderservice')) . '</div>';
                    echo '<img class="orderservice-sig-img" alt="' . $esc(__('Client signature', 'orderservice')) . '" src="' . $esc($clientSig) . '">';
                    echo '<div class="small mt-1">' . $esc($signedByName);
                    if ($signedAt !== '') {
                        echo ' - ' . $esc((string) Html::convDateTime($signedAt));
                    }
                    echo '</div>';
                    echo '</div></div>';
                }
                echo '</div>';
            } elseif ($editable) {
                echo '<div class="d-flex flex-wrap gap-2 mb-3">';
                echo '<form method="post" action="' . $esc($formUrl) . '" class="d-inline">';
                echo '<input type="hidden" name="tickets_id" value="' . $ticketsId . '">';
                echo '<input type="hidden" name="orders_id" value="' . $orderId . '">';
                echo '<input type="hidden" name="os_action" value="generate_token">';
                echo '<button type="submit" class="btn btn-outline-primary">' . $esc(__('Generate Remote Signature Link', 'orderservice')) . '</button>';
                Html::closeForm();

                echo '<form method="post" action="' . $esc($formUrl) . '" class="d-inline">';
                echo '<input type="hidden" name="tickets_id" value="' . $ticketsId . '">';
                echo '<input type="hidden" name="orders_id" value="' . $orderId . '">';
                echo '<input type="hidden" name="os_action" value="cancel_order">';
                echo '<button type="submit" class="btn btn-outline-danger">' . $esc(__('Cancel Order', 'orderservice')) . '</button>';
                Html::closeForm();
                echo '</div>';

                $clientSigInputId = 'os-client-sig-' . $orderId;
                $techSigInputId   = 'os-tech-sig-' . $orderId;

                echo '<form method="post" action="' . $esc($formUrl) . '" class="border rounded p-3 os-local-sign-form">';
                echo '<input type="hidden" name="tickets_id" value="' . $ticketsId . '">';
                echo '<input type="hidden" name="orders_id" value="' . $orderId . '">';
                echo '<input type="hidden" name="os_action" value="sign_local">';
                echo '<h4 class="mb-2">' . $esc(__('In-person signature', 'orderservice')) . '</h4>';
                echo '<div class="mb-2">';
                echo '<label class="form-label">' . $esc(__('Signer full name', 'orderservice')) . '</label>';
                echo '<input type="text" name="signed_by_name" class="form-control" required maxlength="255">';
                echo '</div>';

                if ($techSig === null) {
                    echo '<div class="mb-2">';
                    echo '<label class="form-label">' . $esc(__('Technician signature (optional)', 'orderservice')) . '</label>';
                    self::renderSignaturePad($techSigInputId, 'tech_signature');
                    echo '</div>';
                }

                echo '<div class="mb-3">';
                echo '<label class="form-label">' . $esc(__('Client signature', 'orderservice')) . '</label>';
                self::renderSignaturePad($clientSigInputId, 'client_signature', true);
                echo '</div>';

                echo '<button type="submit" class="btn btn-success">' . $esc(__('Complete Signature', 'orderservice')) . '</button>';
                Html::closeForm();
            }

            echo '</div></div>';
        }

        echo '</div>';
        return true;
    }

    private static function renderSignaturePad(string $inputId, string $fieldName, bool $required = false): void
    {
        $esc         = [PluginOrderserviceCompat::class, 'escapeHtml'];
        $statusOk    = __('Signature captured', 'orderservice');
        $statusEmpty = __('Draw signature above', 'orderservice');
        $msgRequired = __('Signature required', 'orderservice');
        echo '<div class="os-signature-pad" data-input-id="' . $esc($inputId) . '" data-required="' . ($required ? '1' : '0') . '"'
            . ' data-status-ok="' . $esc($statusOk) . '"'
            . ' data-status-empty="' . $esc($statusEmpty) . '"'
            . ' data-msg-required="' . $esc($msgRequired) . '">';
        echo '<canvas class="os-signature-canvas" width="460" height="150"></canvas>';
        echo '<input type="hidden" id="' . $esc($inputId) . '" name="' . $esc($fieldName) . '" value="">';
        echo '<div class="os-signature-toolbar mt-1 d-flex align-items-center justify-content-between">';
        echo '<span class="small text-muted os-signature-status">' . $esc($statusEmpty) . '</span>';
        echo '<button type="button" class="btn btn-sm btn-outline-secondary os-clear-sig">' . $esc(__('Clear', 'orderservice')) . '</button>';
        echo '</div></div>';
    }
}

if (!class_exists('PluginOrderserviceTab', false)) {
    if (PluginOrderserviceCompat::typedRightname()) {
        class PluginOrderserviceTab extends CommonGLPI implements PluginOrderserviceTabConstants
        {
            use PluginOrderserviceTabBody;

            public static string $rightname = 'ticket';
        }
    } else {
        class PluginOrderserviceTab extends CommonGLPI implements PluginOrderserviceTabConstants
        {
            use PluginOrderserviceTabBody;

            public static $rightname = 'ticket';
        }
    }
}
