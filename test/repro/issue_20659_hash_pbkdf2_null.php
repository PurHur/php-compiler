<?php
/**
 * Issue #20659 — hash_pbkdf2() null password/salt TypeError on PROFILE=8.4.
 */
try {
    echo hash_pbkdf2('sha256', null, 'salt', 1), "\n";
} catch (TypeError $e) {
    echo "password=TYPEERROR\n";
}
try {
    echo hash_pbkdf2('sha256', 'p', null, 1), "\n";
} catch (TypeError $e) {
    echo "salt=TYPEERROR\n";
}
echo 'empty=', hash_pbkdf2('sha256', '', 'salt', 1) !== '' ? 'ok' : 'bad', "\n";
