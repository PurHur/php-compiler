--TEST--
Reflection: attribute_exists() agrees with ReflectionClass::getAttributes() (#17632, ext/reflection/php_reflection.c)
--SKIPIF--
<?php
if (getenv('PHP_COMPILER_PROFILE') !== '8.4' && getenv('PHP_COMPILER_PROFILE') !== '8.5') {
    die('skip attribute_exists requires PHP_COMPILER_PROFILE=8.4');
}
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

#[\AllowDynamicProperties]
class AllowDynDemo {}

#[\Attribute]
class Marker {}

#[Marker]
class MarkerTarget {}

function probe(string $label, string $attribute, object|string $target): void
{
    $class = is_object($target) ? $target::class : $target;
    $reflectionCount = count((new ReflectionClass($class))->getAttributes());
    $exists = attribute_exists($attribute, $target);
    echo $label, '_reflection_count=', $reflectionCount, "\n";
    echo $label, '_attribute_exists=', var_export($exists, true), "\n";
}

probe('allow_dynamic', 'AllowDynamicProperties', AllowDynDemo::class);
probe('user_marker', 'Marker', MarkerTarget::class);
--EXPECT--
allow_dynamic_reflection_count=1
allow_dynamic_attribute_exists=true
user_marker_reflection_count=1
user_marker_attribute_exists=true
