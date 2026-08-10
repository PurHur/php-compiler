<?php

declare(strict_types=1);

try {
    date_diff(date_create('@0'), null);
    echo "fail:null\n";
} catch (TypeError $e) {
    echo 'ok:null:', $e->getMessage(), "\n";
}

try {
    $a = new DateTimeImmutable('@0');
    $b = new DateTime('@86400');
    echo 'ok:imm:', date_diff($a, $b)->days, "\n";
} catch (TypeError $e) {
    echo 'fail:imm:', $e->getMessage(), "\n";
}
