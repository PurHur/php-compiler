--TEST--
header() Reflection $replace default true (VM, issue #25258, ext/standard/head.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('header');
foreach ($r->getParameters() as $p) {
    if (!$p->isOptional()) {
        continue;
    }
    echo $p->getName().'_default=';
    echo $p->isDefaultValueAvailable()
        ? var_export($p->getDefaultValue(), true)
        : 'NONE';
    echo PHP_EOL;
}
?>
--EXPECT--
replace_default=true
response_code_default=0
