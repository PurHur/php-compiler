<?php

declare(strict_types=1);

// Compile-only (#11492): json_encode() named flags:/depth:
echo json_encode(value: [1], flags: JSON_FORCE_OBJECT), "\n";
echo json_encode([1], depth: 2), "\n";
