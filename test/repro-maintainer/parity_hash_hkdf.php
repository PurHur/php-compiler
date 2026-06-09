<?php
echo strlen(hash_hkdf('sha256', 'key', 16, 'info', 'salt')), "\n";
echo bin2hex(hash_hkdf('sha256', 'key', 16, 'info', 'salt')), "\n";
echo bin2hex(hash_hkdf('sha256', 'key', 32)), "\n";
try {
    hash_hkdf('nope', 'k', 16);
    echo "no error\n";
} catch (ValueError $e) {
    echo get_class($e), "\n";
}
