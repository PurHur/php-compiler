<?php

declare(strict_types=1);

/**
 * Maintainer repro: nested scalar-return builtin in call argument (#10673).
 */

echo substr(sprintf('%o', 33188), -4), "\n";
