<?php
declare(strict_types=1);
/**
 * #29046 — filter_var() options['default'] on validation failure (php-src php_zval_filter handle_default).
 */
var_export(filter_var('x', FILTER_VALIDATE_INT, ['options' => ['default' => 42]]));
echo "\n";
var_export(filter_var('x', FILTER_VALIDATE_INT, [
    'options' => ['default' => 42],
    'flags' => FILTER_NULL_ON_FAILURE,
]));
echo "\n";
var_export(filter_var('nope', FILTER_VALIDATE_EMAIL, [
    'options' => ['default' => 'fallback@example.com'],
]));
echo "\n";
var_export(filter_var('ok@example.com', FILTER_VALIDATE_EMAIL, [
    'options' => ['default' => 'fallback@example.com'],
]));
echo "\n";
