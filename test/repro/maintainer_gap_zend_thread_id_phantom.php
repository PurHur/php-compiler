<?php

declare(strict_types=1);

// Issue #11842 — zend_thread_id() is PHP 8.4+; Zend 8.2 reference must not advertise it.
if (function_exists('zend_thread_id')) {
    echo "fail: function_exists('zend_thread_id') true on reference profile\n";
    exit(1);
}

echo "ok\n";
