<?php

declare(strict_types=1);

/**
 * Issue #10797: json_encode() float stringification must match Zend dtoa + PRESERVE_ZERO_FRACTION.
 */
echo json_encode(0.1 + 0.2), "\n";
echo json_encode(0.1 + 0.2, JSON_PRESERVE_ZERO_FRACTION), "\n";
echo json_encode(1.0, JSON_PRESERVE_ZERO_FRACTION), "\n";
echo json_encode(42.0), "\n";
echo json_encode(42.0, JSON_PRESERVE_ZERO_FRACTION), "\n";
