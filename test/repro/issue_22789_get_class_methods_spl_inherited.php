<?php
// Issue #22789 — get_class_methods must list inherited SPL methods.
$f = new SplFileObject(__FILE__);
$gcm = get_class_methods($f);
echo 'gcm_count=', count($gcm), PHP_EOL;
echo 'gcm_has_getFilename=', in_array('getFilename', $gcm, true) ? 'yes' : 'no', PHP_EOL;
echo 'call=', $f->getFilename(), PHP_EOL;
$refCount = count((new ReflectionClass(SplFileObject::class))->getMethods(ReflectionMethod::IS_PUBLIC));
echo 'ref_count=', $refCount, PHP_EOL;

$s = new SplStack();
$gcmS = get_class_methods($s);
$refS = count((new ReflectionClass(SplStack::class))->getMethods(ReflectionMethod::IS_PUBLIC));
echo 'stack_gcm=', count($gcmS), ' stack_ref=', $refS, PHP_EOL;

$r = new RecursiveArrayIterator([]);
$gcmR = get_class_methods($r);
$refR = count((new ReflectionClass(RecursiveArrayIterator::class))->getMethods(ReflectionMethod::IS_PUBLIC));
echo 'rai_gcm=', count($gcmR), ' rai_ref=', $refR, PHP_EOL;

$t = new SplTempFileObject();
$gcmT = get_class_methods($t);
echo 'temp_has_getFilename=', in_array('getFilename', $gcmT, true) ? 'yes' : 'no', PHP_EOL;
echo 'temp_gcm=', count($gcmT), PHP_EOL;
