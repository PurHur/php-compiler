<?php

declare(strict_types=1);

// Issue #11300 — nested inline array literal as http_build_query() arg #1 (ext/standard/http.c).
echo http_build_query(['a' => ['b' => 1, 'c' => 2]], '', '&', PHP_QUERY_RFC3986), "\n";
