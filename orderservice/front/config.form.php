<?php

/**
 * ------------------------------------------------------------------------
 * Order Service - Painel administrativo de configuração global
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

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($requestMethod === 'POST') {
    Session::checkRight('config', UPDATE);
    if (!PluginOrderserviceCompat::requireSafePost()) {
        PluginOrderserviceCompat::denyAccess();
        return;
    }

    if (!empty($_POST['update'])) {
        $companyName     = PluginOrderserviceCompat::fromSanitizedInput((string) ($_POST['company_name'] ?? ''));
        $companyDocument = PluginOrderserviceCompat::fromSanitizedInput((string) ($_POST['company_document'] ?? ''));
        $currencySymbol  = PluginOrderserviceCompat::fromSanitizedInput((string) ($_POST['currency_symbol'] ?? 'R$'));
        $travelRaw       = PluginOrderserviceCompat::fromSanitizedInput((string) ($_POST['default_travel_amount'] ?? '0'));
        $termsFooter     = PluginOrderserviceCompat::fromSanitizedInput((string) ($_POST['terms_footer'] ?? ''));

        PluginOrderserviceConfig::saveConfig([
            'company_name'         => $companyName,
            'company_document'     => $companyDocument,
            'currency_symbol'      => $currencySymbol,
            'default_travel_cents' => PluginOrderserviceOrder::parseDecimalToInt($travelRaw),
            'allow_public_sign'    => isset($_POST['allow_public_sign']) ? 1 : 0,
            'token_ttl_hours'      => $_POST['token_ttl_hours'] ?? PluginOrderserviceConfigConstants::DEFAULT_TOKEN_TTL_HOURS,
            'terms_footer'         => $termsFooter,
        ]);

        Session::addMessageAfterRedirect(
            __('Order Service configuration saved successfully.', 'orderservice'),
            false,
            defined('INFO') ? INFO : 0
        );
        Html::back();
        return;
    }
}

Session::checkRight('config', READ);

$config  = PluginOrderserviceConfig::getConfig();
$canEdit = Session::haveRight('config', UPDATE);
$formUrl = PluginOrderserviceCompat::webPath() . '/front/config.form.php';
$esc     = [PluginOrderserviceCompat::class, 'escapeHtml'];

Html::header(
    __('Order Service', 'orderservice'),
    $_SERVER['PHP_SELF'] ?? $formUrl,
    'config',
    'plugins'
);

echo '<div class="container-xl my-4 orderservice-config-page">';
echo '<div class="card">';
echo '<div class="card-header"><h2 class="card-title mb-0">' . $esc(__('Order Service Configuration', 'orderservice')) . '</h2></div>';
echo '<div class="card-body">';

echo '<form method="post" action="' . $esc($formUrl) . '">';
echo '<div class="row g-3">';

echo '<div class="col-md-6">';
echo '<label class="form-label">' . $esc(__('Company name', 'orderservice')) . '</label>';
echo '<input type="text" name="company_name" class="form-control" maxlength="180" value="' . $esc((string) $config['company_name']) . '"' . ($canEdit ? '' : ' disabled') . '>';
echo '</div>';

echo '<div class="col-md-6">';
echo '<label class="form-label">' . $esc(__('Company document (Tax ID / CNPJ)', 'orderservice')) . '</label>';
echo '<input type="text" name="company_document" class="form-control" maxlength="64" value="' . $esc((string) $config['company_document']) . '"' . ($canEdit ? '' : ' disabled') . '>';
echo '</div>';

echo '<div class="col-md-4">';
echo '<label class="form-label">' . $esc(__('Currency symbol', 'orderservice')) . '</label>';
echo '<input type="text" name="currency_symbol" class="form-control" maxlength="12" value="' . $esc((string) $config['currency_symbol']) . '"' . ($canEdit ? '' : ' disabled') . '>';
echo '</div>';

echo '<div class="col-md-4">';
echo '<label class="form-label">' . $esc(__('Default travel fee', 'orderservice')) . '</label>';
echo '<input type="text" name="default_travel_amount" class="form-control" value="' . $esc(PluginOrderserviceOrder::formatDecimalInput((int) $config['default_travel_cents'])) . '"' . ($canEdit ? '' : ' disabled') . '>';
echo '</div>';

echo '<div class="col-md-4">';
echo '<label class="form-label">' . $esc(__('Remote signature link expiration (hours)', 'orderservice')) . '</label>';
echo '<input type="number" name="token_ttl_hours" min="1" max="720" class="form-control" value="' . (int) $config['token_ttl_hours'] . '"' . ($canEdit ? '' : ' disabled') . '>';
echo '</div>';

echo '<div class="col-12">';
echo '<div class="form-check">';
echo '<input class="form-check-input" type="checkbox" id="os-allow-public-sign" name="allow_public_sign" value="1"'
    . ((int) $config['allow_public_sign'] === 1 ? ' checked' : '')
    . ($canEdit ? '' : ' disabled') . '>';
echo '<label class="form-check-label" for="os-allow-public-sign">'
    . $esc(__('Allow external clients to sign via one-time remote link without GLPI login', 'orderservice'))
    . '</label>';
echo '</div></div>';

echo '<div class="col-12">';
echo '<label class="form-label">' . $esc(__('Terms and footer notes', 'orderservice')) . '</label>';
echo '<textarea name="terms_footer" rows="4" class="form-control" maxlength="2000"' . ($canEdit ? '' : ' disabled') . '>'
    . $esc((string) $config['terms_footer'])
    . '</textarea>';
echo '</div>';

echo '</div>';

if ($canEdit) {
    echo '<div class="mt-4">';
    echo '<button type="submit" name="update" value="1" class="btn btn-primary">' . $esc(__('Save settings', 'orderservice')) . '</button>';
    echo '</div>';
}

Html::closeForm();

echo '</div></div></div>';

Html::footer();
