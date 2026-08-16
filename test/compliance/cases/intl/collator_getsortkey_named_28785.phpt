--TEST--
Collator::getSortKey Reflection + named string (#28785)
--SKIPIF--
<?php if (!extension_loaded('intl')) die('skip host php-intl required'); ?>
--FILE--
<?php
$r = new ReflectionMethod(Collator::class, 'getSortKey');
echo 'arity=', $r->getNumberOfParameters(), PHP_EOL;
echo 'req=', $r->getNumberOfRequiredParameters(), PHP_EOL;
foreach ($r->getParameters() as $p) {
    echo 'p=', $p->getName();
    echo ' type=', $p->hasType() ? (string) $p->getType() : '(none)';
    echo ' opt=', $p->isOptional() ? '1' : '0';
    echo PHP_EOL;
}
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;
$c = new Collator('en_US');
try {
    $k = $c->getSortKey(string: 'abc');
    echo 'named=', is_string($k) ? 'ok' : gettype($k), PHP_EOL;
} catch (Throwable $e) {
    echo 'named=', get_class($e), ': ', $e->getMessage(), PHP_EOL;
}
try {
    $c->getSortKey(str: 'abc');
    echo "legacy_str accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
$pos = $c->getSortKey('abc');
echo 'pos=', is_string($pos) ? 'ok' : gettype($pos), PHP_EOL;
?>
--EXPECT--
arity=1
req=1
p=string type=string opt=0
ret=string|false
named=ok
Unknown named parameter $str
pos=ok
