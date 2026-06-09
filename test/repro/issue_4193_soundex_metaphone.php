<?php
declare(strict_types=1);

try {
    soundex(123);
} catch (Throwable $e) {
    echo 'soundex: ', get_class($e), "\n";
}

try {
    metaphone(true);
} catch (Throwable $e) {
    echo 'metaphone: ', get_class($e), "\n";
}

try {
    soundex([]);
} catch (Throwable $e) {
    echo 'soundex array: ', get_class($e), "\n";
}
