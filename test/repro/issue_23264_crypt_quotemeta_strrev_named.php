<?php
// Repro #23264 — crypt/quotemeta/strrev/str_rot13 Zend stub named parameters (string)
$checks = [];
foreach (['quotemeta', 'strrev', 'str_rot13'] as $fn) {
    $names = [];
    foreach ((new ReflectionFunction($fn))->getParameters() as $p) {
        $names[] = $p->getName();
    }
    $checks[] = ['string'] === $names;
    try {
        if ('quotemeta' === $fn) {
            $checks[] = quotemeta('.+') === quotemeta(string: '.+');
        } elseif ('strrev' === $fn) {
            $checks[] = 'ba' === strrev(string: 'ab');
        } else {
            $checks[] = 'no' === str_rot13(string: 'ab');
        }
    } catch (Throwable $e) {
        $checks[] = false;
    }
    try {
        if ('quotemeta' === $fn) {
            quotemeta(str: '.+');
        } elseif ('strrev' === $fn) {
            strrev(str: 'ab');
        } else {
            str_rot13(str: 'ab');
        }
        $checks[] = false;
    } catch (Error $e) {
        $checks[] = str_contains($e->getMessage(), 'Unknown named parameter $str');
    }
}

$cryptNames = [];
foreach ((new ReflectionFunction('crypt'))->getParameters() as $p) {
    $cryptNames[] = $p->getName();
}
$checks[] = ['string', 'salt'] === $cryptNames;
try {
    $hash = crypt(string: 'x', salt: '$1$xxxxxxxx$');
    $checks[] = is_string($hash) && '' !== $hash;
} catch (Throwable $e) {
    $checks[] = false;
}
try {
    crypt(str: 'x', salt: '$1$xxxxxxxx$');
    $checks[] = false;
} catch (Error $e) {
    $checks[] = str_contains($e->getMessage(), 'Unknown named parameter $str');
}

echo (!in_array(false, $checks, true)) ? "ok\n" : "fail\n";
