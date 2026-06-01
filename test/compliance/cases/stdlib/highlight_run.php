<?php

$html = highlight_string('<?php echo 1; ?>', true);
echo strpos($html, '<code>') !== false ? "code\n" : "no-code\n";
echo strpos($html, 'echo') !== false ? "echo-kw\n" : "no-echo\n";
echo strpos($html, '<span') !== false ? "span\n" : "no-span\n";
echo strlen($html) > 20 ? "len\n" : "short\n";

$sample = __DIR__ . '/highlight_sample.php';
$fileHtml = highlight_file($sample, true);
echo strpos($fileHtml, '<code>') !== false ? "file-code\n" : "no-file-code\n";
$showHtml = show_source($sample, true);
echo strpos($showHtml, '<code>') !== false ? "show-code\n" : "no-show-code\n";
echo highlight_file('/nonexistent/path_' . getmypid() . '.php', true) === false ? "missing-false\n" : "missing-ok\n";
echo show_source('/nonexistent/path_' . getmypid() . '.php', true) === false ? "show-missing-false\n" : "show-missing-ok\n";
