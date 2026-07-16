--TEST--
MessageFormatter / msgfmt_* create/format subset (#6366)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip MessageFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
echo 'intl_loaded=', (int) extension_loaded('intl'), "\n";
echo 'class=', (int) class_exists('MessageFormatter', false), "\n";
echo 'create=', (int) function_exists('msgfmt_create'), "\n";
$fmt = msgfmt_create('en_US', '{0, number} files');
echo msgfmt_format($fmt, [3]), "\n";
$oop = MessageFormatter::create('en_US', '{name}');
$oop->setPattern('{name} uploaded');
echo $oop->format(['name' => 'doc']), "\n";
echo msgfmt_format_message('en_US', '{0} x', [1]), "\n";
?>
--EXPECT--
intl_loaded=1
class=1
create=1
3 files
doc uploaded
1 x
