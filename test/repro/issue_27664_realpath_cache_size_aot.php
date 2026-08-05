<?php

declare(strict_types=1);

/**
 * #27664 — thin AOT realpath_cache_size() must compile and return int (empty snapshot = 0).
 * Avoid ternary/concat that also crash under thin AOT (#27665).
 */
echo gettype(realpath_cache_size());
echo '|';
echo (string) realpath_cache_size();
echo "\n";
