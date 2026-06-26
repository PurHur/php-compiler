<?php

declare(strict_types=1);

$fail = 0;

try {
    $result = hash_hkdf('sha256', '', 32);
    echo 'result_len='.\strlen($result)."\n";
    $fail = 1;
} catch (ValueError $e) {
    if ('hash_hkdf(): Argument #2 ($key) cannot be empty' !== $e->getMessage()) {
        echo 'bad message: '.$e->getMessage()."\n";
        $fail = 1;
    }
}

echo $fail === 0 ? "ok\n" : "fail\n";
exit($fail);
