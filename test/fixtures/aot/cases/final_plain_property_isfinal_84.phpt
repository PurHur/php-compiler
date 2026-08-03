--TEST--
AOT: ReflectionProperty::isFinal() for final plain property under PROFILE=8.4 (#27315)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class A {
    final public string $x = 'a';
}
$a = new A;
$a->x = 'b';
echo "wrote:", $a->x, "\n";
echo 'isFinal=', (int) (new ReflectionProperty(A::class, 'x'))->isFinal(), "\n";
--EXPECT--
wrote:b
isFinal=1
