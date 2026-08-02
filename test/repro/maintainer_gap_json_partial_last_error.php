<?php

declare(strict_types=1);

// #26792 — JSON_PARTIAL_OUTPUT_ON_ERROR keeps JSON_ERROR_INF_OR_NAN sticky (ext/json/json.c).
// Single encode: AOT still crashes on a second compile-time json_encode fold in one TU (pre-existing).
$r = json_encode(['x' => INF], JSON_PARTIAL_OUTPUT_ON_ERROR);
echo 'out=', $r, "\n";
echo 'err=', json_last_error(), "\n";
echo 'msg=', json_last_error_msg(), "\n";
