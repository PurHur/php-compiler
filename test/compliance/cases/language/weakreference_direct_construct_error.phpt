--TEST--
language WeakReference direct construct throws Error (#24432, Zend/zend_weakrefs.c)
--FILE--
<?php
try {
    new WeakReference(new stdClass());
    echo "FAIL: no throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$o = new stdClass();
$r = WeakReference::create($o);
echo $r->get() === $o ? "create_ok\n" : "create_fail\n";
--EXPECT--
Error: Direct instantiation of WeakReference is not allowed, use WeakReference::create instead
create_ok
