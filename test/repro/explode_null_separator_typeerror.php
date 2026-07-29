<?php
declare(strict_types=1);

// null separator must throw TypeError (php-src Z_PARAM_STR rejects null)
try {
    explode(null, 'a');
    echo "BUG: no exception\n";
} catch (TypeError $e) {
    echo "OK TypeError: ", $e->getMessage(), "\n";
} catch (\ValueError $e) {
    echo "BUG ValueError: ", $e->getMessage(), "\n";
}

// empty string separator must still throw ValueError
try {
    explode('', 'a');
    echo "BUG: no exception\n";
} catch (\ValueError $e) {
    echo "OK ValueError: ", $e->getMessage(), "\n";
}
