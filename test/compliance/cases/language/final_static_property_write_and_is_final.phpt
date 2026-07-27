--TEST--
Language: final static property writes allowed; isFinal true (#23683, #23403, Zend)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class S {
    public final static string $x = "a";
}
S::$x = "z";
echo "WROTE:", S::$x, "\n";
$r = new ReflectionProperty("S", "x");
echo "isFinal=", $r->isFinal() ? "1" : "0", "\n";
echo "modifiers=", $r->getModifiers(), "\n";
--EXPECT--
WROTE:z
isFinal=1
modifiers=49
