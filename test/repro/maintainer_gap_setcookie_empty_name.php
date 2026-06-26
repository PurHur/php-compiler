<?php

declare(strict_types=1);

// Issue #11945 — setcookie/setrawcookie empty name must ValueError (ext/standard/head.c).
try {
    setcookie('');
    echo "fail: setcookie empty name succeeded\n";
    exit(1);
} catch (ValueError) {
}
try {
    setrawcookie('');
    echo "fail: setrawcookie empty name succeeded\n";
    exit(1);
} catch (ValueError) {
}
echo "ok\n";
