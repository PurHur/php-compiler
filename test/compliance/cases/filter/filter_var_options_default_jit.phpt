--TEST--
JIT filter_var() options[default] on validation failure (#29046)
--FILE--
<?php
declare(strict_types=1);
var_export(filter_var('x', FILTER_VALIDATE_INT, ['options' => ['default' => 42]]));
echo "\n";
var_export(filter_var('nope', FILTER_VALIDATE_EMAIL, [
    'options' => ['default' => 'fallback@example.com'],
]));
echo "\n";
--EXPECT--
42
'fallback@example.com'
