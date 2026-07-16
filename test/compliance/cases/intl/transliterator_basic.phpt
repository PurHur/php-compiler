--TEST--
Transliterator create/transliterate Latin-ASCII subset (#6139)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip Transliterator withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
echo 'intl_loaded=', (int) extension_loaded('intl'), "\n";
echo 'class=', (int) class_exists('Transliterator', false), "\n";
echo 'create=', (int) function_exists('transliterator_create'), "\n";
$tr = transliterator_create('Any-Latin; Latin-ASCII');
echo $tr === false || $tr === null ? 'null' : 'obj', "\n";
echo transliterator_transliterate($tr, 'café'), "\n";
$bad = transliterator_create('Not-A-Real-ID-XYZ');
echo $bad === false || $bad === null ? 'bad_null' : 'bad_obj', "\n";
?>
--EXPECT--
intl_loaded=1
class=1
create=1
obj
cafe
bad_null
