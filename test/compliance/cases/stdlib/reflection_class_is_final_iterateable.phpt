--TEST--
ReflectionClass::isFinal()/isIterateable() (#18297, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

final class FinalUser {}

echo (new ReflectionClass('Closure'))->isFinal() ? "closure_final_yes\n" : "closure_final_no\n";
echo (new ReflectionClass('Generator'))->isFinal() ? "generator_final_yes\n" : "generator_final_no\n";
echo (new ReflectionClass('stdClass'))->isFinal() ? "stdclass_final_yes\n" : "stdclass_final_no\n";
echo (new ReflectionClass('FinalUser'))->isFinal() ? "user_final_yes\n" : "user_final_no\n";
echo (new ReflectionClass('ArrayObject'))->isIterateable() ? "arrayobject_iterateable_yes\n" : "arrayobject_iterateable_no\n";
echo (new ReflectionClass('stdClass'))->isIterateable() ? "stdclass_iterateable_yes\n" : "stdclass_iterateable_no\n";
--EXPECT--
closure_final_yes
generator_final_yes
stdclass_final_no
user_final_yes
arrayobject_iterateable_yes
stdclass_iterateable_no
