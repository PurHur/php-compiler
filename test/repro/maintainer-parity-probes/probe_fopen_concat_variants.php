<?php

declare(strict_types=1);

// Repro for #13458 — inline concat path args must match Zend.

@fopen('/tmp/maint_99/sub/file.txt', 'r');
$e1 = error_get_last();
echo "literal: " . ($e1['message'] ?? 'none') . "\n";

@fopen('/tmp/maint_' . 99 . '/sub/file.txt', 'r');
$e2 = error_get_last();
echo "inline_concat: " . ($e2['message'] ?? 'none') . "\n";

$p = '/tmp/maint_' . 99 . '/sub/file.txt';
@fopen($p, 'r');
$e3 = error_get_last();
echo "variable: " . ($e3['message'] ?? 'none') . "\n";

@file_put_contents('/tmp/maint_' . 99 . '/sub/file.txt', 'x');
$e4 = error_get_last();
echo "file_put_contents: " . ($e4['message'] ?? 'none') . "\n";
