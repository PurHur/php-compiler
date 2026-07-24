<?php
declare(strict_types=1);

// Issue #22834 — positional dynamic width/precision (php-src formatted_print.c).
echo json_encode(sprintf('%2$*1$s', 5, 'z')), "\n";
echo json_encode(sprintf('%1$.*2$s', 'abcdef', 3)), "\n";
echo json_encode(sprintf('%3$*1$.*2$s', 8, 3, 'abcdef')), "\n";
echo json_encode(sprintf('%2$*1$d', 5, 42)), "\n";
