<?php
$o = new stdClass();
try {
    array_flip([$o => 1]);
    echo "object no exception\n";
} catch (Throwable $e) {
    echo 'object ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    array_flip([true => 1]);
    echo "bool no exception\n";
} catch (Throwable $e) {
    echo 'bool ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    array_flip([1.5 => 1]);
    echo "float no exception\n";
} catch (Throwable $e) {
    echo 'float ', get_class($e), ': ', $e->getMessage(), "\n";
}
