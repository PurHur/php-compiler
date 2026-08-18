<?php
/**
 * #32154 — include() of a syntax-error file is a catchable ParseError (php-src ZEND_INCLUDE_OR_EVAL).
 * Must not abort the process with parseAndCompile failure / PhpParser dump.
 */
try {
    include __DIR__ . '/maintainer_gap_include_parse_bad.php';
    echo "after";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage();
}
