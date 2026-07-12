<?php
declare(strict_types=1);

#[\Attribute]
class Marker {}

#[Marker]
class Target {}

$ref = new ReflectionClass(Target::class);
$reflectionCount = count($ref->getAttributes());
$exists = attribute_exists('Marker', Target::class);

echo 'reflection_count=', $reflectionCount, "\n";
echo 'attribute_exists=', var_export($exists, true), "\n";
