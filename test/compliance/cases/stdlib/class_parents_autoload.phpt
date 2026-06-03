--TEST--
Stdlib: class_parents() autoload flag — VM (issue #5026)
--FILE--
<?php
class AutoloadBase5026 {}
class AutoloadChild5026 extends AutoloadBase5026 {}

$pTrue = class_parents(AutoloadChild5026::class, true);
$pFalse = class_parents(AutoloadChild5026::class, false);
echo count($pTrue), "\n";
echo $pTrue['AutoloadBase5026'], "\n";
echo count($pFalse), "\n";
echo $pFalse['AutoloadBase5026'], "\n";

interface AutoloadIface5026 {}
echo class_parents(AutoloadIface5026::class, true) === false ? 'iface-false' : 'iface-other';
echo "\n";
--EXPECT--
1
AutoloadBase5026
1
AutoloadBase5026
iface-false
