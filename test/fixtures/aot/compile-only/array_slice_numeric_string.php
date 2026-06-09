<?php
// Compile-only (#4176): array_slice() Z_PARAM_LONG numeric-string + TypeError lowering.
echo json_encode(array_slice([1, 2, 3], '1', '1')), "\n";
try {
    array_slice([1], 'abc');
} catch (TypeError $e) {
    echo get_class($e), "\n";
}
