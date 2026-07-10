<?php

declare(strict_types=1);

$f = tempnam(sys_get_temp_dir(), 'hl');
if (false === $f) {
    fwrite(STDERR, "tempnam failed\n");
    exit(1);
}
file_put_contents($f, "line1\nline2\n");
$html = highlight_file($f, true);
unlink($f);

if (!is_string($html)) {
    fwrite(STDERR, "expected string HTML\n");
    exit(1);
}

$brCount = substr_count($html, '<br');
$hasRawContentNewline = strpos($html, "line1\nline2") !== false;

echo 'br_count='.$brCount."\n";
echo 'raw_nl_in_span='.($hasRawContentNewline ? 'true' : 'false')."\n";
