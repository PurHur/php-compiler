<?php

declare(strict_types=1);

/**
 * Issue #12588 — Deprecated attribute class must not register on Zend 8.2 reference profile.
 */

if (class_exists('Deprecated', false)) {
    echo "fail: Deprecated attribute class registered on Zend 8.2 reference profile\n";
    exit(1);
}

echo "ok\n";
