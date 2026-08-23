--TEST--
stdlib hrtime Reflection as_number optional default false (#25146, ext/standard/basic_functions.stub.php)
--FILE--
<?php
declare(strict_types=1);

$p = (new ReflectionFunction('hrtime'))->getParameters()[0];
echo 'optional=', $p->isOptional() ? 'yes' : 'no';
echo ' def=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : '-';
echo "\n";
$pair = hrtime();
echo 'pair=', is_array($pair) && 2 === count($pair) ? 'ok' : 'bad';
echo "\n";
--EXPECT--
optional=yes def=false
pair=ok
