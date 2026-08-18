--TEST--
JIT: msgfmt_format_message named values: + unknown $args (#24504)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip MessageFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--JIT--
--FILE--
<?php
$rf = new ReflectionFunction('msgfmt_format_message');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), PHP_EOL;
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
?>
--EXPECT--
locale,pattern,values
values=Hi Ada
Unknown named parameter $args
