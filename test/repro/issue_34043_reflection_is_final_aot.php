<?php
/**
 * #34043 — ReflectionClass::isFinal under thin AOT.
 * php-src: ext/reflection/php_reflection.c zim_ReflectionClass_isFinal
 */
class U {}
final class F {}
enum E { case X; }
abstract class A {}
echo 'U=', (new ReflectionClass('U'))->isFinal() ? '1' : '0', "\n";
echo 'F=', (new ReflectionClass('F'))->isFinal() ? '1' : '0', "\n";
echo 'E=', (new ReflectionClass('E'))->isFinal() ? '1' : '0', "\n";
echo 'A=', (new ReflectionClass('A'))->isFinal() ? '1' : '0', "\n";
echo 'C=', (new ReflectionClass(Closure::class))->isFinal() ? '1' : '0', "\n";
echo 'G=', (new ReflectionClass(Generator::class))->isFinal() ? '1' : '0', "\n";
