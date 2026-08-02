<?php

/**
 * #26861 — AOT password_hash(PASSWORD_BCRYPT) must verify like Zend (no SEGV / false hash).
 *
 * Root causes fixed: (1) AOT exported strspn/strcspn under libc names → libxcrypt
 * crypt(3) returned *0; (2) NestedJIT bcryptEncodeSalt22 strlen($out) after .= never
 * grew → infinite loop / SEGV.
 */
$h = password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]);
if (!\is_string($h)) {
    echo "type=", \gettype($h), "\n";
    exit(2);
}
echo \password_verify('secret', $h) ? 'ok' : 'bad', "\n";
echo \password_verify('wrong', $h) ? 'bad' : 'ok', "\n";
