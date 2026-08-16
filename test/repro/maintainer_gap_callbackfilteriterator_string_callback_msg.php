<?php
/** Maintainer gap: CallbackFilterIterator unknown-function string callback TypeError message (php-src-strict). */
try {
    new CallbackFilterIterator(new ArrayIterator([1]), 'not_callable');
    echo "UNEXPECTED_OK\n";
} catch (Throwable $t) {
    echo get_class($t), ':', $t->getMessage(), "\n";
}
try {
    new RecursiveCallbackFilterIterator(new RecursiveArrayIterator([1]), 'missing_fn');
    echo "UNEXPECTED_OK2\n";
} catch (Throwable $t) {
    echo get_class($t), ':', $t->getMessage(), "\n";
}
