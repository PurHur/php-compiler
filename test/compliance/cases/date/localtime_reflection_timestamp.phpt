--TEST--
localtime Reflection ?int timestamp=null (#27980, ext/date/php_date.stub.php)
--FILE--
<?php
declare(strict_types=1);

$r = new ReflectionFunction('localtime');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' type=', (string) ($p->getType() ?: '-');
    if ($p->isDefaultValueAvailable()) {
        echo ' def=', var_export($p->getDefaultValue(), true);
    } elseif ($p->isOptional()) {
        echo ' def=?';
    }
    echo "\n";
}
$a = localtime(timestamp: null, associative: true);
echo 'named_ok=', array_key_exists('tm_year', $a) ? '1' : '0', "\n";
echo 'null_now=', (localtime(timestamp: null, associative: true)['tm_year'] === ((int) date('Y') - 1900)) ? '1' : '0', "\n";
--EXPECT--
timestamp type=?int def=NULL
associative type=bool def=false
named_ok=1
null_now=1
