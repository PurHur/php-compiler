<?php
/**
 * Maintainer gap: ArrayIterator::seek(null) soft-null.
 * Zend: E_DEPRECATED + seek(0)
 * VM: silent seek(0)
 */
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    echo "WARN[$no]: $str\n";
    return true;
});

$ai = new ArrayIterator([10, 20, 30]);
$ai->seek(null);
echo 'key=' . $ai->key() . ' current=' . $ai->current() . "\n";
