<?php

declare(strict_types=1);

/**
 * Issue #3365 — bcadd() with literal scale must AOT-compile (ext/bcmath).
 */

echo bcadd('1.234', '5', 2), "\n";
