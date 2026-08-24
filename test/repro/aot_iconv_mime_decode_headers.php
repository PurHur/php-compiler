<?php
// AOT: iconv_mime_decode_headers NestedJIT leftover of #19448 (#34441)
$h = "From: =?UTF-8?B?SGVsbG8=?=\r\nTo: a@b.c\r\n\r\n";
$r = iconv_mime_decode_headers($h);
if (!is_array($r)) {
    echo "not_array\n";
    exit(1);
}
echo 'ok:', count($r), ':', ($r['From'] ?? '?'), ':', ($r['To'] ?? '?'), "\n";

// Runtime (non-literal) path — forces NestedJIT ABI, not compile-time fold
$dyn = $h;
$r2 = iconv_mime_decode_headers($dyn);
if (!is_array($r2)) {
    echo "dyn_not_array\n";
    exit(1);
}
echo 'dyn:', ($r2['From'] ?? '?'), '|', ($r2['To'] ?? '?'), "\n";
