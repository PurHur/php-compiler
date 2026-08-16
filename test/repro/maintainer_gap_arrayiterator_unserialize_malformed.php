<?php
/** Maintainer gap: ArrayIterator/ArrayObject::unserialize malformed → UnexpectedValueException (php-src-strict). */
foreach ([new ArrayIterator([1, 2]), new ArrayObject([1])] as $obj) {
    $cls = get_class($obj);
    try {
        $r = $obj->unserialize('x');
        echo $cls, ' ret=';
        var_export($r);
        echo "\n";
    } catch (Throwable $e) {
        echo $cls, ' ', get_class($e), ':', $e->getMessage(), "\n";
    }
}
