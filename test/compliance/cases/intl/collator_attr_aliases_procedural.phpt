--TEST--
collator_get_attribute/set_attribute/get_strength/get_sort_key/get_locale/error_* procedural (#20801)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip Collator withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$col = Collator::create('en_US');
foreach ([
    'collator_get_attribute',
    'collator_set_attribute',
    'collator_get_strength',
    'collator_set_strength',
    'collator_get_sort_key',
    'collator_get_locale',
    'collator_get_error_code',
    'collator_get_error_message',
] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}
$oop = $col->getAttribute(Collator::STRENGTH);
$proc = collator_get_attribute($col, Collator::STRENGTH);
echo 'match_attr=', (int) ($oop === $proc), "\n";
echo 'set_str=', (int) collator_set_strength($col, Collator::PRIMARY), "\n";
echo 'str=', (int) collator_get_strength($col), "\n";
echo 'attr=', (int) collator_get_attribute($col, Collator::STRENGTH), "\n";
echo 'set_attr=', (int) collator_set_attribute($col, Collator::NUMERIC_COLLATION, Collator::ON), "\n";
echo 'numeric=', (int) (Collator::ON === collator_get_attribute($col, Collator::NUMERIC_COLLATION)), "\n";
$key = collator_get_sort_key($col, 'abc');
echo 'sortkey_nonempty=', (int) (is_string($key) && strlen($key) > 0), "\n";
echo 'locale=', collator_get_locale($col, 1), "\n";
echo 'locale_oop=', $col->getLocale(1), "\n";
echo 'err=', collator_get_error_code($col), "\n";
echo 'msg=', collator_get_error_message($col), "\n";
?>
--EXPECT--
collator_get_attribute=1
collator_set_attribute=1
collator_get_strength=1
collator_set_strength=1
collator_get_sort_key=1
collator_get_locale=1
collator_get_error_code=1
collator_get_error_message=1
match_attr=1
set_str=1
str=0
attr=0
set_attr=1
numeric=1
sortkey_nonempty=1
locale=en_US
locale_oop=en_US
err=0
msg=U_ZERO_ERROR
