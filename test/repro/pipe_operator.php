<?php

declare(strict_types=1);

/**
 * Issue #18007 repro — PHP 8.4 pipe operator chained with first-class callable.
 *
 * Zend 8.2: parse error. Forward profile: expect strlen("HELLO") === 5.
 */
echo 'hello' |> strtoupper(...) |> strlen(...), "\n";
