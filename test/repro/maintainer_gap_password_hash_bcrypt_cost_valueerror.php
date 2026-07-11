<?php

declare(strict_types=1);

$fail = 0;

try {
    $result = password_hash('password', PASSWORD_BCRYPT, ['cost' => 3]);
    echo 'result='.var_export($result, true)."\n";
    $fail = 1;
} catch (ValueError $e) {
    if ('Invalid bcrypt cost parameter specified: 3' !== $e->getMessage()) {
        echo 'bad message: '.$e->getMessage()."\n";
        $fail = 1;
    }
}

echo $fail === 0 ? "ok\n" : "fail\n";
exit($fail);
