<?php
/**
 * #4013 / #4096 — user __destruct() on unset and AOT link/execute.
 *
 * php-src: Zend/zend_objects.c zend_objects_destroy_object
 */
class R
{
    public function __destruct()
    {
        echo "dtor\n";
    }
}
$o = new R();
unset($o);
echo "after\n";
