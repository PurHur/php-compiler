--TEST--
AOT: array_slice/chunk(null $preserve_keys) TypeError under strict_types (#31442)
--FILE--
<?php
declare(strict_types=1);
// No closures — AOT foreach+arrow catch path is silent; array_reverse AOT IR verify broken.
try {
    array_slice([1, 2, 3], 0, 1, null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    array_chunk([1, 2], 1, null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
array_slice(): Argument #4 ($preserve_keys) must be of type bool, null given
array_chunk(): Argument #3 ($preserve_keys) must be of type bool, null given
