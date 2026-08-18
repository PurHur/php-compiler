--TEST--
msgfmt_format_message Reflection $values + named values: (#24504)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip MessageFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$rf = new ReflectionFunction('msgfmt_format_message');
echo 'arity=', $rf->getNumberOfParameters(), PHP_EOL;
echo 'req=', $rf->getNumberOfRequiredParameters(), PHP_EOL;
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
    echo 'p=', $p->getName();
    echo ' opt=', $p->isOptional() ? '1' : '0';
    echo PHP_EOL;
}
echo 'names=', implode(',', $names), PHP_EOL;
try {
    echo 'values=', msgfmt_format_message(locale: 'en_US', pattern: 'Hi {0}', values: ['Ada']), PHP_EOL;
} catch (Throwable $e) {
    echo 'values=', get_class($e), ': ', $e->getMessage(), PHP_EOL;
}
try {
    msgfmt_format_message(locale: 'en_US', pattern: 'Hi {0}', args: ['Ada']);
    echo "legacy_args accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
echo 'pos=', msgfmt_format_message('en_US', 'Hi {0}', ['Ada']), PHP_EOL;
?>
--EXPECT--
arity=3
req=3
p=locale opt=0
p=pattern opt=0
p=values opt=0
names=locale,pattern,values
values=Hi Ada
Unknown named parameter $args
pos=Hi Ada
