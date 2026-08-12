<?php

declare(strict_types=1);

$fail = 0;
try {
    $r = Locale::acceptFromHttp(null);
    echo 'oop returned=', var_export($r, true), "\n";
    $fail = 1;
} catch (TypeError $e) {
    if (false === strpos($e->getMessage(), 'null given')
        || false === strpos($e->getMessage(), '($header)')) {
        echo 'oop bad: ', $e->getMessage(), "\n";
        $fail = 1;
    } else {
        echo "oop TypeError null header\n";
    }
}
try {
    $r = locale_accept_from_http(null);
    echo 'proc returned=', var_export($r, true), "\n";
    $fail = 1;
} catch (TypeError $e) {
    if (false === strpos($e->getMessage(), 'null given')
        || false === strpos($e->getMessage(), '($header)')) {
        echo 'proc bad: ', $e->getMessage(), "\n";
        $fail = 1;
    } else {
        echo "proc TypeError null header\n";
    }
}

echo $fail === 0 ? "ok\n" : "fail\n";
exit($fail);
