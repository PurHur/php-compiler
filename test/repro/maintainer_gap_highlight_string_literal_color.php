<?php

declare(strict_types=1);

$html = highlight_string('<?php $x = "x"; ?>', true);
if (!\is_string($html)) {
    echo "fail: highlight_string did not return string\n";
    exit(1);
}
if (!\str_contains($html, '#DD0000')) {
    echo "fail: missing #DD0000 string-literal span\n";
    exit(1);
}
echo "ok\n";
