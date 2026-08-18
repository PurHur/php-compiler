--TEST--
IntlDateFormatter::format Reflection $datetime + named datetime: (#23667)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlDateFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$rf = new ReflectionMethod(IntlDateFormatter::class, 'format');
echo 'arity=', $rf->getNumberOfParameters(), PHP_EOL;
echo 'req=', $rf->getNumberOfRequiredParameters(), PHP_EOL;
foreach ($rf->getParameters() as $p) {
    echo 'p=', $p->getName();
    echo ' opt=', $p->isOptional() ? '1' : '0';
    echo PHP_EOL;
}
$fmt = new IntlDateFormatter(
    'en_US',
    IntlDateFormatter::SHORT,
    IntlDateFormatter::NONE,
    'UTC',
    IntlDateFormatter::GREGORIAN,
    'yyyy-MM-dd'
);
$dt = new DateTime('2020-01-15 UTC');
try {
    echo 'datetime=', $fmt->format(datetime: $dt), PHP_EOL;
} catch (Throwable $e) {
    echo 'datetime=', get_class($e), ': ', $e->getMessage(), PHP_EOL;
}
try {
    $fmt->format(args: $dt);
    echo "legacy_args accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
echo 'pos=', $fmt->format($dt), PHP_EOL;
?>
--EXPECT--
arity=1
req=1
p=datetime opt=0
datetime=2020-01-15
Unknown named parameter $args
pos=2020-01-15
