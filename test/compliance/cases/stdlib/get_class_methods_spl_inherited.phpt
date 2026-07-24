--TEST--
Stdlib: get_class_methods() includes inherited SplFileInfo/ArrayIterator methods (#22789)
--FILE--
<?php
$f = new SplTempFileObject();
$gcm = get_class_methods($f);
echo 'gcm_has_getFilename=', in_array('getFilename', $gcm, true) ? 'yes' : 'no', "\n";
echo 'gcm_has_getPath=', in_array('getPath', $gcm, true) ? 'yes' : 'no', "\n";
echo 'gcm_has_isFile=', in_array('isFile', $gcm, true) ? 'yes' : 'no', "\n";
$ref = (new ReflectionClass(SplFileObject::class))->getMethods(ReflectionMethod::IS_PUBLIC);
$gcmFo = get_class_methods(SplFileObject::class);
echo 'gcm_matches_ref=', count($gcmFo) === count($ref) ? 'yes' : 'no', "\n";

$s = new SplStack();
$gcmS = get_class_methods($s);
$refS = (new ReflectionClass(SplStack::class))->getMethods(ReflectionMethod::IS_PUBLIC);
echo 'stack_gcm_matches_ref=', count($gcmS) === count($refS) ? 'yes' : 'no', "\n";
echo 'stack_has_push=', in_array('push', $gcmS, true) ? 'yes' : 'no', "\n";

$r = new RecursiveArrayIterator([]);
$gcmR = get_class_methods($r);
$refR = (new ReflectionClass(RecursiveArrayIterator::class))->getMethods(ReflectionMethod::IS_PUBLIC);
echo 'rai_gcm_matches_ref=', count($gcmR) === count($refR) ? 'yes' : 'no', "\n";
echo 'rai_has_offsetGet=', in_array('offsetGet', $gcmR, true) ? 'yes' : 'no', "\n";

$t = new SplTempFileObject();
$gcmT = get_class_methods($t);
echo 'temp_has_getFilename=', in_array('getFilename', $gcmT, true) ? 'yes' : 'no', "\n";
--EXPECT--
gcm_has_getFilename=yes
gcm_has_getPath=yes
gcm_has_isFile=yes
gcm_matches_ref=yes
stack_gcm_matches_ref=yes
stack_has_push=yes
rai_gcm_matches_ref=yes
rai_has_offsetGet=yes
temp_has_getFilename=yes
