<?php

declare(strict_types=1);

// Issue #22661 — grapheme_levenshtein() must not be advertised (Zend/php-src never ships it).
echo function_exists('grapheme_levenshtein') ? "fail\n" : "ok\n";
