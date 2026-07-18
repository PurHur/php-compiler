--TEST--
Transliterator createFromRules/createInverse/listIDs + error accessors (#20719)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip Transliterator withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$r = new ReflectionClass('Transliterator');
foreach (['createFromRules', 'createInverse', 'listIDs', 'getErrorCode', 'getErrorMessage'] as $m) {
    echo $m, '=', $r->hasMethod($m) ? 'yes' : 'MISSING', "\n";
}
$ids = Transliterator::listIDs();
echo 'listIDs=', is_array($ids) ? 'array' : gettype($ids), "\n";
echo 'list_count_ok=', (int) (is_array($ids) && count($ids) > 10), "\n";
echo 'has_Latin-ASCII=', (int) (is_array($ids) && in_array('Latin-ASCII', $ids, true)), "\n";
$t = Transliterator::createFromRules('a > b;');
echo 'fromRules=', is_object($t) ? 'obj' : 'null', "\n";
echo 'rules_out=', is_object($t) ? $t->transliterate('a') : '-', "\n";
echo 'err0=', is_object($t) ? (int) (0 === $t->getErrorCode()) : 0, "\n";
$src = Transliterator::create('Latin-ASCII');
$inv = is_object($src) ? $src->createInverse() : null;
echo 'inverse=', is_object($inv) ? 'obj' : 'null', "\n";
echo 'proc=', (int) function_exists('transliterator_create_from_rules'), "\n";
$p = transliterator_create_from_rules('x > y;');
echo 'proc_out=', is_object($p) ? transliterator_transliterate($p, 'x') : '-', "\n";
?>
--EXPECT--
createFromRules=yes
createInverse=yes
listIDs=yes
getErrorCode=yes
getErrorMessage=yes
listIDs=array
list_count_ok=1
has_Latin-ASCII=1
fromRules=obj
rules_out=b
err0=1
inverse=obj
proc=1
proc_out=y
