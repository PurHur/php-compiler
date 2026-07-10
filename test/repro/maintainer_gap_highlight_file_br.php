<?php

declare(strict_types=1);

$f = tempnam(sys_get_temp_dir(), 'hlbr');
if (false === $f) {
    fwrite(STDERR, "fail: tempnam\n");
    exit(1);
}
file_put_contents($f, "line1\nline2\n");

$html = highlight_file($f, true);
unlink($f);

if (!is_string($html)) {
    fwrite(STDERR, "fail: not string\n");
    exit(1);
}

$brCount = substr_count($html, '<br');
$contentNewline = false !== strpos($html, "line1\nline2");

echo 'br_count='.$brCount."\n";
echo 'content_newline='.var_export($contentNewline, true)."\n";

if (2 !== $brCount) {
    fwrite(STDERR, "fail: expected 2 <br /> separators\n");
    exit(1);
}
if ($contentNewline) {
    fwrite(STDERR, "fail: raw newline between highlighted lines\n");
    exit(1);
}

echo "ok\n";
