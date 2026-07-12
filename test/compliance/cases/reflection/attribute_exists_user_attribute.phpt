--TEST--
Reflection: attribute_exists() on user-defined #[Attribute] class (#17327, ext/reflection/php_reflection.c)
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

#[\Attribute]
class Marker {}

#[Marker]
class Target {}

$reflectionCount = count((new ReflectionClass(Target::class))->getAttributes());
$exists = attribute_exists('Marker', Target::class);

echo 'reflection_count=', $reflectionCount, "\n";
echo 'attribute_exists=', var_export($exists, true), "\n";
--EXPECT--
reflection_count=1
attribute_exists=true
