<?php
declare(strict_types=1);

print_r(array_chunk([1, 2, 3, 4, 5], "2"));
print_r(array_chunk([1, 2, 3], 2.9));

try {
    array_chunk([1, 2, 3], 'abc');
} catch (TypeError $e) {
    echo get_class($e), "\n";
}

try {
    array_chunk([1, 2, 3], []);
} catch (TypeError $e) {
    echo get_class($e), "\n";
}
