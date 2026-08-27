<?php

/**
 * #35339 — AOT json_encode(JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) must match Zend.
 */
echo json_encode(['a' => 1, 'p' => 'a/b'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
echo json_encode(['a' => 1], JSON_PRETTY_PRINT), "\n";
echo json_encode(['a' => 1], 192), "\n";
