<?php

// Maintainer gap #14879 — Zend compile-time fatal, not runtime output.
list(0 => $x, $y) = [1, 2];
echo "ran: " . $x . $y . "\n";
