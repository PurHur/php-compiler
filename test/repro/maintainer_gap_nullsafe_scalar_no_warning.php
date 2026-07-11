<?php

declare(strict_types=1);

/**
 * Issue #18028 — nullsafe ?-> on scalar/non-object must short-circuit silently (Zend/zend_execute.c).
 *
 * Zend: echo (1)?->foo ?? 'nullsafe', "\n";  → nullsafe\n (no warnings)
 * VM before fix: duplicate Warning + interleaved stdout
 */
echo (1)?->foo ?? 'nullsafe', "\n";
