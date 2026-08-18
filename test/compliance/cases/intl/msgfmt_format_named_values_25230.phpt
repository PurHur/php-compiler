--TEST--
MessageFormatter::format Reflection $values + named values: (#25230)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip MessageFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$rf = new ReflectionMethod(MessageFormatter::class, 'format');
echo 'arity=', $rf->getNumberOfParameters(), PHP_EOL;
echo 'req=', $rf->getNumberOfRequiredParameters(), PHP_EOL;
foreach ($rf->getParameters() as $p) {
    echo 'p=', $p->getName();
    echo ' opt=', $p->isOptional() ? '1' : '0';
    echo PHP_EOL;
}
$fmt = MessageFormatter::create('en_US', '{0}');
try {
    echo 'values=', $fmt->format(values: ['x']), PHP_EOL;
} catch (Throwable $e) {
    echo 'values=', get_class($e), ': ', $e->getMessage(), PHP_EOL;
}
try {
    $fmt->format(args: ['x']);
    echo "legacy_args accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
echo 'pos=', $fmt->format(['x']), PHP_EOL;
?>
--EXPECT--
arity=1
req=1
p=values opt=0
values=x
Unknown named parameter $args
pos=x
