<?php
/** Maintainer gap: RecursiveTreeIterator(ArrayIterator) exception class/text (php-src-strict). */
try {
    new RecursiveTreeIterator(new ArrayIterator([1]));
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
