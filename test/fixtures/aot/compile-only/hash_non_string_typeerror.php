<?php
declare(strict_types=1);

try {
    hash('md5', []);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    hash_hmac('md5', 'data', new stdClass());
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
