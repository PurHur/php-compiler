--TEST--
ReflectionMethod::__toString includes param types, optional defaults, return (#22522)
--FILE--
<?php
class C {
    public function f(int $x, string $y = 'a'): ?array { return null; }
    private static function g(): void {}
}
$f = (new ReflectionMethod(C::class, 'f'))->__toString();
$g = (new ReflectionMethod(C::class, 'g'))->__toString();
echo (str_contains($f, "Parameter #0 [ <required> int \$x ]")
    && str_contains($f, "Parameter #1 [ <optional> string \$y = 'a' ]")
    && str_contains($f, '- Return [ ?array ]')) ? "f-ok\n" : "f-bad\n$f";
echo (str_contains($g, "- Parameters [0] {")
    && str_contains($g, '- Return [ void ]')
    && !str_contains($g, 'Undefined property')) ? "g-ok\n" : "g-bad\n$g";
?>
--EXPECT--
f-ok
g-ok
