<?php
declare(strict_types=1);

// #29047 — FILTER_REQUIRE_ARRAY filters list elements; scalar top-level still fails.
var_export(filter_var(['1', '2', 'x'], FILTER_VALIDATE_INT, FILTER_REQUIRE_ARRAY));
echo "\n";
var_export(filter_var('1', FILTER_VALIDATE_INT, FILTER_REQUIRE_ARRAY));
echo "\n";
