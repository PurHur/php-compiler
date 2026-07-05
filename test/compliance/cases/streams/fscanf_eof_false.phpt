--TEST--
streams fscanf() on empty php://memory — false at EOF, no TypeError after reopen (#16271, ext/standard/formatted_io.c)
--FILE--
<?php

declare(strict_types=1);

$f = fopen('php://memory', 'r+');
var_export(fscanf($f, '%s'));
fclose($f);
echo "\n";

$f = fopen('php://memory', 'r+');
var_export(fscanf($f, '%d'));
fclose($f);
echo "\n";
--EXPECT--
false
false
