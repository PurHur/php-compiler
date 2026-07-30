<?php
/**
 * Issue #24824 AOT — named length hole + replacement (Reflection is VM-only under AOT today).
 */
$a = [1, 2, 3, 4];
$removed = array_splice(array: $a, offset: 1, replacement: ['x']);
echo json_encode($removed), '/', json_encode($a), "\n";
$b = [1, 2, 3, 4];
$r2 = array_splice($b, 1, null, ['y']);
echo json_encode($r2), '/', json_encode($b), "\n";
