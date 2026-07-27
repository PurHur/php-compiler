--TEST--
AOT: final plain property writes allowed after construction (#23683, php-src-strict)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public final string $x = "a";
}
$o = new C;
$o->x = "b";
echo "WROTE:", $o->x, "\n";
echo "isFinal=", (new ReflectionProperty("C", "x"))->isFinal() ? "1" : "0", "\n";
--EXPECT--
WROTE:b
isFinal=1
