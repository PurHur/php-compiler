--TEST--
resourcebundle_create/get/locales procedural aliases (#20814)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip ResourceBundle withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
foreach (['resourcebundle_create', 'resourcebundle_get', 'resourcebundle_locales', 'resourcebundle_count'] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}
$rb = resourcebundle_create('en', null);
echo 'create=', ($rb instanceof ResourceBundle) ? '1' : '0', "\n";
$oop = ResourceBundle::create('en', null);
$verProc = resourcebundle_get($rb, 'Version');
$verOop = $oop->get('Version');
echo 'get_ok=', (int) (is_string($verProc) && $verProc !== ''), "\n";
echo 'get_match=', (int) ($verProc === $verOop), "\n";
$localesProc = resourcebundle_locales('ICUDATA');
$localesOop = ResourceBundle::getLocales('ICUDATA');
echo 'locales_ok=', (int) (is_array($localesProc) && count($localesProc) > 0), "\n";
echo 'locales_match=', (int) ($localesProc === $localesOop), "\n";
echo 'count_match=', (int) (resourcebundle_count($rb) === $rb->count()), "\n";
?>
--EXPECT--
resourcebundle_create=1
resourcebundle_get=1
resourcebundle_locales=1
resourcebundle_count=1
create=1
get_ok=1
get_match=1
locales_ok=1
locales_match=1
count_match=1
