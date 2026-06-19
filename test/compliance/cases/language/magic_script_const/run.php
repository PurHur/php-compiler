<?php

declare(strict_types=1);

$html = highlight_string('<?php echo 1; ?>', true);
echo gettype($html), "\n";

$sample = __DIR__ . '/../../stdlib/highlight_sample.php';
$fileHtml = highlight_file($sample, true);
echo gettype($fileHtml), "\n";

echo __DIR__, "\n";
echo __FILE__, "\n";
echo __LINE__, "\n";
