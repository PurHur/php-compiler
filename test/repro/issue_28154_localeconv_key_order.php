<?php
// Repro #28154 — localeconv() insertion order matches php-src (grouping last).
$keys = array_keys(localeconv());
echo implode(',', $keys), "\n";
