--TEST--
LC_NUMERIC constant survives probe label echo after prior call (#11312, Zend/zend_constants.h)
--FILE--
<?php
declare(strict_types=1);

function out($label, $value): void
{
    echo $label . ': ' . var_export($value, true) . "\n";
}

out('warmup', 1);
echo 'LC_NUMERIC=' . var_export(LC_NUMERIC, true) . "\n";
var_export(setlocale(LC_NUMERIC, '0'));
echo "\n";
--EXPECT--
warmup: 1
LC_NUMERIC=1
'C'
