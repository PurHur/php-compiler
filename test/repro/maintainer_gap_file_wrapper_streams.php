<?php

declare(strict_types=1);

$fail = 0;

$memory = @file('php://memory');
if (!\is_array($memory) || [] !== $memory) {
    echo "fail: file(php://memory) expected []\n";
    ++$fail;
}

$data = @file('data://text/plain,hi');
if (!\is_array($data) || ['hi'] !== $data) {
    echo 'fail: file(data://) expected [hi], got ';
    var_export($data);
    echo "\n";
    ++$fail;
}

$flags = @file('data://text/plain,a' . "\n" . 'b', FILE_IGNORE_NEW_LINES);
if (!\is_array($flags) || ['a', 'b'] !== $flags) {
    echo "fail: file() FILE_IGNORE_NEW_LINES\n";
    ++$fail;
}

echo 0 === $fail ? "ok\n" : "fail\n";
