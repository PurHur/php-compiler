<?php
/** Maintainer gap: RegexIterator::setMode invalid mode ValueError cites wrong method/arg (php-src-strict). */
$r = new RegexIterator(new ArrayIterator(['a']), '/a/');
try {
    $r->setMode(99);
    echo "UNEXPECTED_OK\n";
} catch (Throwable $t) {
    echo get_class($t), ':', $t->getMessage(), "\n";
}
