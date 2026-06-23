<?php

declare(strict_types=1);

// Maintainer repro for #10954 — json_encode(JSON_PARTIAL_OUTPUT_ON_ERROR) for INF/NAN.

echo json_encode(NAN, JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
echo json_encode([NAN], JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
echo json_encode(INF, JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
echo json_last_error() === JSON_ERROR_INF_OR_NAN ? '7' : 'n', "\n";
