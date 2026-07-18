<?php
// Repro for #20719 — Transliterator createFromRules / createInverse / listIDs / error accessors
$r = new ReflectionClass('Transliterator');
foreach (['createFromRules', 'createInverse', 'listIDs', 'getErrorCode', 'getErrorMessage'] as $m) {
    echo $m, '=', $r->hasMethod($m) ? 'yes' : 'MISSING', PHP_EOL;
}
$ids = Transliterator::listIDs();
echo 'listIDs=', is_array($ids) ? 'array' : gettype($ids), ' count=', is_array($ids) ? count($ids) : 0, PHP_EOL;
echo 'has_Latin-ASCII=', (int) (is_array($ids) && in_array('Latin-ASCII', $ids, true)), PHP_EOL;
$t = Transliterator::createFromRules('a > b;');
echo 'fromRules=', is_object($t) ? 'obj' : var_export($t, true), PHP_EOL;
if (is_object($t)) {
    echo 'rules_out=', $t->transliterate('a'), PHP_EOL;
    echo 'err=', $t->getErrorCode(), ' msg=', $t->getErrorMessage(), PHP_EOL;
}
$src = Transliterator::create('Latin-ASCII');
$inv = is_object($src) ? $src->createInverse() : null;
echo 'inverse=', is_object($inv) ? 'obj' : var_export($inv, true), PHP_EOL;
$proc = transliterator_create_from_rules('x > y;');
echo 'proc=', is_object($proc) ? 'obj' : var_export($proc, true), PHP_EOL;
if (is_object($proc)) {
    echo 'proc_out=', transliterator_transliterate($proc, 'x'), PHP_EOL;
}
