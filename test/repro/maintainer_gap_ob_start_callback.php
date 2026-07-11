<?php

declare(strict_types=1);

/**
 * Maintainer repro for #3623 — ob_start() closure callback.
 */
ob_start(static fn (string $buffer, int $phase): string => strtoupper($buffer));
echo 'hi';
echo ob_get_clean();
