--TEST--
Language: file-scope const with enum case materializes enum case object (#9711, zend_constants.c)
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'x'; }

const G = E::A;

echo get_debug_type(G), "\n";
var_export(G === E::A);
echo "\n";
$user = get_defined_constants(true)['user'] ?? [];
echo get_debug_type($user['G'] ?? null), "\n";
var_export(($user['G'] ?? null) === E::A);
echo "\n";
--EXPECT--
E
true
E
true
