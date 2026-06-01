<?php

$html = highlight_string('<?php echo 1; ?>', true);
echo strpos($html, '<code>') !== false ? "code\n" : "no-code\n";
echo strpos($html, 'echo') !== false ? "echo-kw\n" : "no-echo\n";
echo strpos($html, '<span') !== false ? "span\n" : "no-span\n";
echo strlen($html) > 20 ? "len\n" : "short\n";

$sample = __DIR__ . '/highlight_sample.php';
$fileHtml = highlight_file($sample, true);
echo strpos($fileHtml, '<code>') !== false ? "file-code\n" : "no-file-code\n";
echo highlight_file('/nonexistent/path_' . getmypid() . '.php', true) === false ? "missing-false\n" : "missing-ok\n";
