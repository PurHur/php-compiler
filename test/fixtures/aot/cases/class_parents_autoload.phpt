--TEST--
AOT class_parents() autoload flag (issue #5026)
--FILE--
<?php
class AotAutoloadBase5026 {}
class AotAutoloadChild5026 extends AotAutoloadBase5026 {}

$p = class_parents(AotAutoloadChild5026::class, true);
echo count($p) === 1 && ($p['AotAutoloadBase5026'] ?? '') === 'AotAutoloadBase5026' ? '1' : '0';
echo class_parents(AotAutoloadChild5026::class, false)['AotAutoloadBase5026'] === 'AotAutoloadBase5026' ? '1' : '0';
--EXPECT--
11
