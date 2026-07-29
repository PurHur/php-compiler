<?php
/**
 * #25070 AOT — range omitted/named step.
 * Note: native AOT currently returns empty arrays for range() (pre-existing;
 * even range(1,3,1) is empty). JIT/VM are correct. Kept for when AOT lands.
 */
echo json_encode(range(1, 3)), "\n";
echo json_encode(range(start: 1, end: 5, step: 2)), "\n";
