<?php

declare(strict_types=1);

$sample = __DIR__ . '/highlight_sample.php';
$fileHtml = highlight_file($sample, true);
echo gettype($fileHtml), "\n";
echo is_string($fileHtml) && str_contains($fileHtml, '<code>') ? "file-code\n" : "no-file-code\n";

$filePath = __FILE__;
$fromFile = highlight_file(dirname($filePath) . '/highlight_sample.php', true);
echo gettype($fromFile), "\n";
