--TEST--
language: echo var_export($arr[key] ?? x, true) . suffix after prior echo (#18315, Zend/zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

$arr = ['algoName' => 'bcrypt'];
echo $arr['algoName'] ?? 'default';
echo "\n";
echo var_export($arr['algoName'] ?? 'default', true) . "\n";
?>
--EXPECT--
bcrypt
'bcrypt'
