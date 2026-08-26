<?php
// #35235 — Q encode then B decode in one AOT binary must not SIGSEGV (re-#35225)
function s(string $x): string
{
    return $x;
}
echo mb_encode_mimeheader(s('café'), s('UTF-8'), s('Q')), "\n";
echo mb_decode_mimeheader(s('=?UTF-8?B?Y2Fmw6k=?=')), "\n";
