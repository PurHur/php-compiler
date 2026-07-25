--TEST--
ReflectionProperty::isFinal() true for public private(set) (#23068, zend_API.c / ext/reflection)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class Example
{
    public string $name = 'n';
    final protected int $age = 1;
    public private(set) string $job = 'x';
}
$rClass = new ReflectionClass(Example::class);
$name = $rClass->getProperty('name')->isFinal();
$age = $rClass->getProperty('age')->isFinal();
$jobFinal = $rClass->getProperty('job')->isFinal();
$flags = [$name, $age, $jobFinal];
var_export($flags);
echo "\n";
$job = $rClass->getProperty('job');
echo ($job->getModifiers() & ReflectionProperty::IS_FINAL) !== 0 ? "final-bit\n" : "no-final-bit\n";
var_export($job->isPrivateSet());
echo "\n";
--EXPECT--
array (
  0 => false,
  1 => true,
  2 => true,
)
final-bit
true
