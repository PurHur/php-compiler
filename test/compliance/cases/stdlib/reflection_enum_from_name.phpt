--TEST--
stdlib ReflectionEnum::fromName() — static enum case factory (#16877, ext/reflection/php_reflection.c)
--FILE--
<?php
enum E { case A; case B; }
$case = ReflectionEnum::fromName('E', 'A');
echo $case instanceof ReflectionEnumUnitCase ? 'unit' : 'other', "\n";
echo $case->getName(), "\n";
try {
    ReflectionEnum::fromName('E', 'NoSuchCase');
    echo "bad\n";
} catch (ReflectionException $e) {
    echo "ReflectionException\n";
}
try {
    ReflectionEnum::fromName('NotAnEnum', 'x');
    echo "bad2\n";
} catch (ReflectionException $e) {
    echo "noenum\n";
}
?>
--EXPECT--
unit
A
ReflectionException
noenum
