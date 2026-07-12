--TEST--
ReflectionClass::isInterface()/isTrait()/getModifiers() (#18335, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

trait ProbeTrait {}
abstract class AbstractUser {}
final class FinalUser {}

echo (new ReflectionClass('Iterator'))->isInterface() ? "iterator_interface_yes\n" : "iterator_interface_no\n";
echo (new ReflectionClass('Stringable'))->isTrait() ? "stringable_trait_yes\n" : "stringable_trait_no\n";
echo (new ReflectionClass('ProbeTrait'))->isTrait() ? "probe_trait_yes\n" : "probe_trait_no\n";
echo (new ReflectionClass('stdClass'))->getModifiers(), "\n";
echo (new ReflectionClass('AbstractUser'))->getModifiers(), "\n";
echo (new ReflectionClass('FinalUser'))->getModifiers(), "\n";
--EXPECT--
iterator_interface_yes
stringable_trait_no
probe_trait_yes
0
64
32
