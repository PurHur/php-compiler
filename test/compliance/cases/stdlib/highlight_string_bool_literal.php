<?php

declare(strict_types=1);

$html = highlight_string('<?php echo 1; ?>', true);
echo gettype($html), "\n";
echo str_contains($html, '<code>') ? "code\n" : "no-code\n";

$path = __DIR__ . '/highlight_sample.php';
$fileHtml = highlight_file($path, true);
echo gettype($fileHtml), "\n";
echo str_contains((string) $fileHtml, '<code>') ? "file-code\n" : "no-file-code\n";
