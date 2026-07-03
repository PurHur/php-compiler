--TEST--
ReflectionMethod on trait-aliased method — declaring class + visibility (#15628, ext/reflection/php_reflection.c)
--FILE--
<?php
trait T {
    public function f(): int {
        return 1;
    }
}
class C {
    use T {
        f as private g;
    }
}
$viaGetMethod = (new ReflectionClass(C::class))->getMethod('g');
$viaCtor = new ReflectionMethod(C::class, 'g');
echo $viaGetMethod->getDeclaringClass()->getName(), "\n";
echo $viaCtor->getDeclaringClass()->getName(), "\n";
echo $viaGetMethod->isPrivate() ? '1' : '0', "\n";
echo $viaCtor->isPrivate() ? '1' : '0', "\n";
--EXPECT--
C
C
1
1
