--TEST--
AOT: ReflectionProperty::isFinal() for trait-imported final plain property under PROFILE=8.4 (#27818)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
trait T {
    final public string $x = 't';
}
class A {
    use T;
}
$a = new A;
$a->x = 'z';
echo "wrote:", $a->x, "\n";
echo 'isFinal=', (int) (new ReflectionProperty(A::class, 'x'))->isFinal(), "\n";
--EXPECT--
wrote:z
isFinal=1
