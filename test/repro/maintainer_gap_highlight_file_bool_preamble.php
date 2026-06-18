<?php
$html = highlight_string('<?php echo 1; ?>', true);
echo "ok1\n";
$sample = __DIR__ . '/highlight_sample.php';
$fileHtml = highlight_file($sample, true);
echo gettype($fileHtml), "\n";
