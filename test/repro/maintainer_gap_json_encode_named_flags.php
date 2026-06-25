<?php

declare(strict_types=1);

echo json_encode(value: [1], flags: JSON_FORCE_OBJECT), "\n";
echo json_encode([1], depth: 2), "\n";
