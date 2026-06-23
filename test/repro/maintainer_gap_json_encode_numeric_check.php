<?php

declare(strict_types=1);

// Maintainer repro for #10601 — json_encode(JSON_NUMERIC_CHECK) numeric strings.

echo json_encode(['1', 2, '3.0'], JSON_NUMERIC_CHECK), "\n";
