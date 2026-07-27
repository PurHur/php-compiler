<?php
// Repro #23273 — stripslashes / quoted_printable_* Zend stub named parameters (string)
$checks = [];
foreach (['stripslashes', 'quoted_printable_encode', 'quoted_printable_decode'] as $fn) {
    $names = [];
    foreach ((new ReflectionFunction($fn))->getParameters() as $p) {
        $names[] = $p->getName();
    }
    $checks[] = ['string'] === $names;
    try {
        if ('stripslashes' === $fn) {
            $v = stripslashes(string: 'a\\b');
            $checks[] = 'ab' === $v;
        } elseif ('quoted_printable_encode' === $fn) {
            $v = quoted_printable_encode(string: 'a=b');
            $checks[] = 'a=3Db' === $v;
        } else {
            $v = quoted_printable_decode(string: 'a=3Db');
            $checks[] = 'a=b' === $v;
        }
    } catch (Throwable $e) {
        $checks[] = false;
    }
    try {
        if ('stripslashes' === $fn) {
            stripslashes(str: 'a\\b');
        } elseif ('quoted_printable_encode' === $fn) {
            quoted_printable_encode(str: 'a=b');
        } else {
            quoted_printable_decode(str: 'a=3Db');
        }
        $checks[] = false;
    } catch (Error $e) {
        $checks[] = str_contains($e->getMessage(), 'Unknown named parameter $str');
    }
}
echo (!in_array(false, $checks, true)) ? "ok\n" : "fail\n";
