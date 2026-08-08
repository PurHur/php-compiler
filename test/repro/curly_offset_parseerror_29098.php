<?php
/**
 * #29098 — PROFILE≥8.4: `$a{0}` is catchable ParseError (Zend 8.4+), not custom Fatal.
 */
try {
    eval('$a="hi"; echo $a{0};');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
