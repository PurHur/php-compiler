<?php

declare(strict_types=1);

var_dump(hash_equals('a', 'a'));
var_dump(hash_equals('a', 'b'));
try {
    var_dump(hash_equals('a', ['a']));
} catch (Throwable $e) {
    echo 'array:', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_dump(hash_equals(1, 'a'));
} catch (Throwable $e) {
    echo 'int:', get_class($e), ': ', $e->getMessage(), "\n";
}
