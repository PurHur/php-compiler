<?php
// Issue #11686 — getrusage(true) must TypeError like Zend PHP 8+.
try {
    getrusage(true);
    echo "no-error\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
