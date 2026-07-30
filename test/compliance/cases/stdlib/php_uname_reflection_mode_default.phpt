--TEST--
stdlib php_uname Reflection mode default "a" (#25261, ext/standard/basic_functions.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('php_uname');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' opt=', (int) $p->isOptional(),
        ' defAvail=', (int) $p->isDefaultValueAvailable();
    if ($p->isDefaultValueAvailable()) {
        echo ' def=', var_export($p->getDefaultValue(), true);
    }
    echo "\n";
}
echo php_uname() === php_uname('a') ? "ok\n" : "mismatch\n";
echo php_uname(mode: 's') !== '' ? "named\n" : "empty\n";
?>
--EXPECT--
mode opt=1 defAvail=1 def='a'
ok
named
