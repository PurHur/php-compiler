--TEST--
Regression: ReflectionClass has no isDeprecated() (#22111, ext/reflection/php_reflection.c)
--FILE--
<?php
/** @deprecated */
class DocblockDeprecated {}

class AttributeDeprecated {}

echo method_exists(ReflectionClass::class, 'isDeprecated') ? 'yes' : 'no', "\n";
$r = new ReflectionClass(DocblockDeprecated::class);
echo method_exists($r, 'isDeprecated') ? 'docblock=yes' : 'docblock=no', "\n";
$r2 = new ReflectionClass(AttributeDeprecated::class);
echo method_exists($r2, 'isDeprecated') ? 'attr=yes' : 'attr=no', "\n";
--EXPECT--
no
docblock=no
attr=no
