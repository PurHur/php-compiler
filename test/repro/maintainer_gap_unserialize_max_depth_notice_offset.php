<?php

declare(strict_types=1);

$payload = 'a:1:{i:0;a:1:{i:0;a:1:{i:0;i:1;}}}';

$result = unserialize($payload, ['max_depth' => 1]);
if (false !== $result) {
    echo "FAIL: expected false\n";
    exit(1);
}

$last = error_get_last();
if (null === $last) {
    echo "FAIL: expected error_get_last notice\n";
    exit(1);
}

$expected = 'unserialize(): Error at offset 14 of 34 bytes';
if ($expected !== $last['message']) {
    echo 'FAIL: notice message mismatch: '.var_export($last['message'], true)."\n";
    exit(1);
}

echo "ok\n";
