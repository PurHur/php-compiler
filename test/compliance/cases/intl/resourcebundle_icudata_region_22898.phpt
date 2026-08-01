--TEST--
ResourceBundle ICUDATA-region key set matches host ICU (no phantom Countries%chagos) (#22898)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip ResourceBundle withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$r = ResourceBundle::create('en', 'ICUDATA-region');
if (false === $r || null === $r) {
    echo "open_fail\n";
    return;
}
$keys = [];
foreach ($r as $k => $_) {
    $keys[] = (string) $k;
}
sort($keys);
echo 'count=', count($r), "\n";
echo 'keys=', implode(',', $keys), "\n";
echo 'has_chagos=', (int) \in_array('Countries%chagos', $keys, true), "\n";
$c = $r->get('Countries');
echo 'countries=', (null === $c || false === $c) ? 'null' : (string) count($c), "\n";
$chagos = @$r->get('Countries%chagos');
echo 'get_chagos=', (null === $chagos || false === $chagos) ? 'null' : 'obj', "\n";
// Host php-intl ICU major: chagos table exists in ICU 74+, not in 72.x (#22898).
$major = 0;
if (\defined('INTL_ICU_VERSION') && 1 === preg_match('/^(\d+)/', (string) INTL_ICU_VERSION, $m)) {
    $major = (int) $m[1];
}
$expectChagos = $major >= 74 ? 1 : 0;
echo 'icu_major=', $major, "\n";
echo 'chagos_ok=', (int) (((int) \in_array('Countries%chagos', $keys, true)) === $expectChagos), "\n";
?>
--EXPECTF--
count=%d
keys=%s
has_chagos=%d
countries=%s
get_chagos=%s
icu_major=%d
chagos_ok=1
