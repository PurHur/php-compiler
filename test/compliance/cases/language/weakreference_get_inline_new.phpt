--TEST--
language WeakReference::get() null after inline new (#11602, zend_weakrefs.c)
--FILE--
<?php
$ref = WeakReference::create(new stdClass());
echo $ref->get() === null ? '1' : '0';
echo "\n";
echo get_debug_type($ref->get());
--EXPECT--
1
null
