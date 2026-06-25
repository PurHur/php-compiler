<?php
// Issue #11763 — strict_types rejects string $length like Zend.
declare(strict_types=1);

try {
    hash_hkdf('sha256', 'secret', '16');
    echo "no error\n";
} catch (TypeError $e) {
    echo get_class($e)."\n";
}
