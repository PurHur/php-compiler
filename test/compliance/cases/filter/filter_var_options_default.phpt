--TEST--
stdlib filter_var() options[default] on validation failure (#29046, ext/filter/filter.c)
--FILE--
<?php
declare(strict_types=1);
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
$forced = filter_var('x', FILTER_VALIDATE_INT, [
    'options' => ['default' => 42],
    'flags' => FILTER_FORCE_ARRAY,
]);
echo is_array($forced) ? ('array:'.$forced[0]) : var_export($forced, true);
echo "\n";
--EXPECT--
42
42
'fallback@example.com'
'ok@example.com'
array:42
