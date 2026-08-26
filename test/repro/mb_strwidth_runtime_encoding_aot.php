<?php

declare(strict_types=1);

/**
 * #34884 — mb_strwidth()/mb_strimwidth() with runtime encoding under thin AOT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_strwidth|mb_strimwidth)
 */
function enc(): string
{
    return 'UTF-8';
}

echo 'w=', mb_strwidth('über', enc()), "\n";
echo 'trim=', mb_strimwidth('übercafé', 0, 5, '...', enc()), "\n";
$ascii = 'ASCII';
echo 'w_ascii=', mb_strwidth('AbC', $ascii), "\n";
try {
    $bad = 'NO_SUCH_ENCODING';
    echo mb_strwidth('x', $bad);
    echo "no error\n";
} catch (ValueError $err) {
    echo 'err=', $err->getMessage(), "\n";
}
try {
    $bad2 = 'NOPE';
    echo mb_strimwidth('xy', 0, 1, '', $bad2);
    echo "no error2\n";
} catch (ValueError $err) {
    echo 'err2=', $err->getMessage(), "\n";
}
