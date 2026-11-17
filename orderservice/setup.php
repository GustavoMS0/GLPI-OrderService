<?php

/**
 * ------------------------------------------------------------------------
 * Order Service - Inicialização e metadados do plugin
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

define('PLUGIN_ORDERSERVICE_VERSION', '1.0.0');
define('PLUGIN_ORDERSERVICE_MIN_GLPI', '10.0.0');
define('PLUGIN_ORDERSERVICE_MAX_GLPI', '12.0.99');
define('PLUGIN_ORDERSERVICE_MIN_PHP', '7.4');

require_once __DIR__ . '/inc/compat.class.php';
require_once __DIR__ . '/inc/config.class.php';
require_once __DIR__ . '/inc/order.class.php';
require_once __DIR__ . '/inc/tab.class.php';

/**
 * Registra os hooks e recursos do plugin no GLPI.
 */
function plugin_init_orderservice(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['orderservice'] = true;
    $PLUGIN_HOOKS['config_page']['orderservice']    = 'front/config.form.php';
    $PLUGIN_HOOKS['add_css']['orderservice']        = [
        PluginOrderserviceCompat::assetPath('css/orderservice.css'),
    ];
    $PLUGIN_HOOKS['add_javascript']['orderservice'] = [
        PluginOrderserviceCompat::assetPath('js/orderservice.js'),
    ];

    Plugin::registerClass('PluginOrderserviceTab', [
        'addtabon' => ['Ticket'],
    ]);

    $PLUGIN_HOOKS['item_purge']['orderservice'] = [
        'Ticket' => [PluginOrderserviceOrder::class, 'purgeTicketOrders'],
    ];

    if (
        class_exists(\Glpi\Http\Firewall::class)
        && method_exists(\Glpi\Http\Firewall::class, 'addPluginStrategyForLegacyScripts')
    ) {
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
            'orderservice',
            '#^/front/sign\.php$#',
            \Glpi\Http\Firewall::STRATEGY_NO_CHECK
        );
    }
}

/**
 * Metadados e requisitos de compatibilidade do plugin.
 *
 * @return array<string, mixed>
 */
function plugin_version_orderservice(): array
{
    return [
        'name'         => __('Order Service', 'orderservice'),
        'version'      => PLUGIN_ORDERSERVICE_VERSION,
        'author'       => 'G. Martins',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/GustavoMS0/GLPI-OrderService',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_ORDERSERVICE_MIN_GLPI,
                'max' => PLUGIN_ORDERSERVICE_MAX_GLPI,
            ],
            'php' => [
                'min' => PLUGIN_ORDERSERVICE_MIN_PHP,
            ],
        ],
    ];
}

/**
 * Verifica os pré-requisitos de versão do PHP e do GLPI.
 */
function plugin_orderservice_check_prerequisites(): bool
{
    if (version_compare(PHP_VERSION, PLUGIN_ORDERSERVICE_MIN_PHP, '<')) {
        echo sprintf(
            __('This plugin requires PHP >= %s.', 'orderservice'),
            PLUGIN_ORDERSERVICE_MIN_PHP
        );
        return false;
    }

    if (defined('GLPI_VERSION')) {
        if (
            version_compare(GLPI_VERSION, PLUGIN_ORDERSERVICE_MIN_GLPI, '<')
            || version_compare(GLPI_VERSION, PLUGIN_ORDERSERVICE_MAX_GLPI, '>')
        ) {
            echo sprintf(
                __('This plugin requires GLPI >= %1$s and <= %2$s.', 'orderservice'),
                PLUGIN_ORDERSERVICE_MIN_GLPI,
                PLUGIN_ORDERSERVICE_MAX_GLPI
            );
            return false;
        }
    }

    return true;
}

/**
 * Verifica a configuração do plugin.
 *
 * @param bool $verbose
 */
function plugin_orderservice_check_config($verbose = false): bool
{
    return true;
}
