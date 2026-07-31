--TEST--
Exception/Error private __clone via Reflection (zend_exceptions.stub.php, #25870)
--FILE--
<?php
$r = new ReflectionClass(Exception::class);
echo "has=" . ($r->hasMethod("__clone") ? "1" : "0") . "\n";
$m = $r->getMethod("__clone");
echo "private=" . ($m->isPrivate() ? "1" : "0") . "\n";
echo "return=" . (string) $m->getReturnType() . "\n";
$r2 = new ReflectionClass(Error::class);
echo "error_has=" . ($r2->hasMethod("__clone") ? "1" : "0") . "\n";
echo "error_private=" . ($r2->getMethod("__clone")->isPrivate() ? "1" : "0") . "\n";
echo "cloneable=" . ($r->isCloneable() ? "1" : "0") . "\n";
try {
    clone new Exception("x");
    echo "cloned\n";
} catch (Error $e) {
    echo "uncloneable\n";
}
--EXPECT--
has=1
private=1
return=void
error_has=1
error_private=1
cloneable=0
uncloneable
