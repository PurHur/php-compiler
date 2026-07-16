--TEST--
ResourceBundle create/get Version (#6187)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip ResourceBundle withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
echo 'intl_loaded=', (int) extension_loaded('intl'), "\n";
echo 'class=', (int) class_exists('ResourceBundle', false), "\n";
$rb = ResourceBundle::create('en', null);
echo $rb === false || $rb === null ? 'null' : 'obj', "\n";
$ver = $rb->get('Version');
echo is_string($ver) && $ver !== '' ? 'version_ok' : 'version_bad', "\n";
echo $ver, "\n";
?>
--EXPECTF--
intl_loaded=1
class=1
obj
version_ok
%S
