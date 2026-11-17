<?php

/**
 * ------------------------------------------------------------------------
 * Order Service - Gerenciamento de configuração global
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

interface PluginOrderserviceConfigConstants
{
    public const CONTEXT = 'plugin:orderservice';

    public const DEFAULT_CURRENCY_SYMBOL      = 'R$';
    public const DEFAULT_TRAVEL_CENTS         = 0;
    public const DEFAULT_ALLOW_PUBLIC_SIGN    = 1;
    public const DEFAULT_TOKEN_TTL_HOURS      = 72;
}

trait PluginOrderserviceConfigBody
{
    public static function getTypeName($nb = 0)
    {
        return __('Order Service', 'orderservice');
    }

    /**
     * Retorna valores padrão da configuração do plugin.
     *
     * @return array<string, int|string>
     */
    public static function defaults(): array
    {
        return [
            'company_name'         => '',
            'company_document'     => '',
            'currency_symbol'      => self::DEFAULT_CURRENCY_SYMBOL,
            'default_travel_cents' => self::DEFAULT_TRAVEL_CENTS,
            'allow_public_sign'    => self::DEFAULT_ALLOW_PUBLIC_SIGN,
            'token_ttl_hours'      => self::DEFAULT_TOKEN_TTL_HOURS,
            'terms_footer'         => '',
        ];
    }

    /**
     * Lê e normaliza a configuração atual armazenada em glpi_configs.
     *
     * @return array<string, int|string>
     */
    public static function getConfig(): array
    {
        $defaults = self::defaults();
        $stored   = [];

        if (class_exists('Config') && method_exists('Config', 'getConfigurationValues')) {
            $raw = Config::getConfigurationValues(self::CONTEXT);
            if (is_array($raw)) {
                $stored = $raw;
            }
        }

        $currency = trim((string) ($stored['currency_symbol'] ?? $defaults['currency_symbol']));
        if ($currency === '') {
            $currency = self::DEFAULT_CURRENCY_SYMBOL;
        }

        return [
            'company_name'         => self::cleanText((string) ($stored['company_name'] ?? $defaults['company_name']), 180),
            'company_document'     => self::cleanText((string) ($stored['company_document'] ?? $defaults['company_document']), 64),
            'currency_symbol'      => self::cleanText($currency, 12),
            'default_travel_cents' => self::clampInt($stored['default_travel_cents'] ?? $defaults['default_travel_cents'], 0, 100000000, self::DEFAULT_TRAVEL_CENTS),
            'allow_public_sign'    => self::clampBool($stored['allow_public_sign'] ?? $defaults['allow_public_sign']),
            'token_ttl_hours'      => self::clampInt($stored['token_ttl_hours'] ?? $defaults['token_ttl_hours'], 1, 720, self::DEFAULT_TOKEN_TTL_HOURS),
            'terms_footer'         => self::cleanMultiline((string) ($stored['terms_footer'] ?? $defaults['terms_footer']), 2000),
        ];
    }

    /**
     * Persiste e retorna a configuração normalizada.
     *
     * @param array<string, mixed> $input
     * @return array<string, int|string>
     */
    public static function saveConfig(array $input): array
    {
        $current = self::getConfig();

        $currency = array_key_exists('currency_symbol', $input)
            ? self::cleanText((string) $input['currency_symbol'], 12)
            : (string) $current['currency_symbol'];
        if ($currency === '') {
            $currency = self::DEFAULT_CURRENCY_SYMBOL;
        }

        $normalized = [
            'company_name' => array_key_exists('company_name', $input)
                ? self::cleanText((string) $input['company_name'], 180)
                : (string) $current['company_name'],
            'company_document' => array_key_exists('company_document', $input)
                ? self::cleanText((string) $input['company_document'], 64)
                : (string) $current['company_document'],
            'currency_symbol' => $currency,
            'default_travel_cents' => array_key_exists('default_travel_cents', $input)
                ? self::clampInt($input['default_travel_cents'], 0, 100000000, self::DEFAULT_TRAVEL_CENTS)
                : (int) $current['default_travel_cents'],
            'allow_public_sign' => array_key_exists('allow_public_sign', $input)
                ? self::clampBool($input['allow_public_sign'])
                : (int) $current['allow_public_sign'],
            'token_ttl_hours' => array_key_exists('token_ttl_hours', $input)
                ? self::clampInt($input['token_ttl_hours'], 1, 720, self::DEFAULT_TOKEN_TTL_HOURS)
                : (int) $current['token_ttl_hours'],
            'terms_footer' => array_key_exists('terms_footer', $input)
                ? self::cleanMultiline((string) $input['terms_footer'], 2000)
                : (string) $current['terms_footer'],
        ];

        if (class_exists('Config') && method_exists('Config', 'setConfigurationValues')) {
            Config::setConfigurationValues(self::CONTEXT, $normalized);
        }

        return $normalized;
    }

    /**
     * Inicializa configuração padrão na instalação se chaves ainda não existirem.
     */
    public static function installDefaults(): void
    {
        if (!class_exists('Config') || !method_exists('Config', 'setConfigurationValues')) {
            return;
        }

        $existing = Config::getConfigurationValues(self::CONTEXT);
        $defaults = self::defaults();
        $missing  = [];

        foreach ($defaults as $key => $val) {
            if (!is_array($existing) || !array_key_exists($key, $existing)) {
                $missing[$key] = $val;
            }
        }

        if ($missing !== []) {
            Config::setConfigurationValues(self::CONTEXT, $missing);
        }
    }

    /**
     * Remove todas as chaves de configuração do plugin na desinstalação.
     */
    public static function uninstallConfig(): void
    {
        if (class_exists('Config') && method_exists('Config', 'deleteConfigurationValues')) {
            Config::deleteConfigurationValues(self::CONTEXT, array_keys(self::defaults()));
        }
    }

    private static function cleanText(string $value, int $maxLen): string
    {
        $clean = trim(preg_replace('/[\r\n\t]+/', ' ', $value));
        if (function_exists('mb_substr')) {
            return mb_substr($clean, 0, $maxLen, 'UTF-8');
        }
        return substr($clean, 0, $maxLen);
    }

    private static function cleanMultiline(string $value, int $maxLen): string
    {
        $clean = trim(str_replace(["\r\n", "\r"], "\n", $value));
        if (function_exists('mb_substr')) {
            return mb_substr($clean, 0, $maxLen, 'UTF-8');
        }
        return substr($clean, 0, $maxLen);
    }

    /**
     * @param mixed $val
     */
    private static function clampBool($val): int
    {
        return ((int) $val === 1 || $val === true || $val === 'true') ? 1 : 0;
    }

    /**
     * @param mixed $val
     */
    private static function clampInt($val, int $min, int $max, int $default): int
    {
        if (!is_numeric($val)) {
            return $default;
        }
        $intVal = (int) $val;
        if ($intVal < $min) {
            return $min;
        }
        if ($intVal > $max) {
            return $max;
        }
        return $intVal;
    }
}

if (!class_exists('PluginOrderserviceConfig', false)) {
    if (PluginOrderserviceCompat::typedRightname()) {
        class PluginOrderserviceConfig extends CommonGLPI implements PluginOrderserviceConfigConstants
        {
            use PluginOrderserviceConfigBody;

            public static string $rightname = 'config';
        }
    } else {
        class PluginOrderserviceConfig extends CommonGLPI implements PluginOrderserviceConfigConstants
        {
            use PluginOrderserviceConfigBody;

            public static $rightname = 'config';
        }
    }
}
