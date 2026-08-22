--TEST--
round() Reflection num int|float and mode default 1 (VM, issue #24825, ext/standard/math.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('round');
foreach ($r->getParameters() as $p) {
    if ('num' === $p->getName()) {
        echo 'num_type=', $p->hasType() ? (string) $p->getType() : 'none', PHP_EOL;
    }
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo $p->getName().'_default=';
        echo var_export($p->getDefaultValue(), true);
        echo PHP_EOL;
    }
}
?>
--EXPECT--
num_type=int|float
precision_default=0
mode_default=1
