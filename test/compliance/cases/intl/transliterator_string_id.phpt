--TEST--
transliterator_transliterate() accepts string ID (Z_PARAM_OBJ_OF_CLASS_OR_STR, #22161)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip Transliterator withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$id = 'Any-Latin; Latin-ASCII';
echo 'str=', transliterator_transliterate($id, '東京'), "\n";
$tr = transliterator_create($id);
echo 'obj=', is_object($tr) ? transliterator_transliterate($tr, '東京') : '-', "\n";
$bad = @transliterator_transliterate('Not-A-Real-ID-XYZ', '東京');
echo 'bad=', var_export($bad, true), "\n";
echo 'has_create_err=', (int) (false !== strpos(intl_get_error_message(), 'transliterator_create')), "\n";
try {
    transliterator_transliterate([], 'x');
    echo "array_ok\n";
} catch (TypeError $e) {
    echo 'union=', (int) (false !== strpos($e->getMessage(), 'Transliterator|string')), "\n";
}
?>
--EXPECT--
str=dong jing
obj=dong jing
bad=false
has_create_err=1
union=1
