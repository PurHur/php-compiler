<?php

/**
 * #31420 — extract() zero argc → ArgumentCountError (Zend), not LogicException.
 *
 * php-src: ext/standard/array.c ZEND_PARSE_PARAMETERS_START(1, 3)
 */
error_reporting(E_ALL);
try {
    extract();
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    extract([], EXTR_OVERWRITE, '', 'extra');
    echo "NO_THROW_EXCESS\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
