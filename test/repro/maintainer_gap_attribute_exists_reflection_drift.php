<?php

declare(strict_types=1);

/**
 * Issue #17632 — attribute_exists() must match ReflectionClass::getAttributes().
 *
 * php-src: ext/reflection/php_reflection.c — PHP_FUNCTION(attribute_exists)
 */

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
