--TEST--
Language: match default arm — single literal arm falls through to default expression (#18589, Zend/zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

var_export(match (2) {
    1 => 'a',
    default => 'b',
});
echo "\n";
--EXPECT--
'b'
