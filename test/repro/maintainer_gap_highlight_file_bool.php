<?php
$sample = __DIR__ . '/highlight_sample.php';
$fileHtml = highlight_file($sample, true);
echo gettype($fileHtml), "\n";
