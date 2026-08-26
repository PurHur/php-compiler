<?php
// #35057 — NestedJIT urlencode family must not segfault on runtime strings
// php-src: ext/standard/url.c
function ue(string $s): string
{
    return urlencode($s);
}
function rue(string $s): string
{
    return rawurlencode($s);
}
function ud(string $s): string
{
    return urldecode($s);
}
function rud(string $s): string
{
    return rawurldecode($s);
}

echo ue('a b&c'), "\n";
echo rue('a b&c'), "\n";
echo ud('a+b%26c'), "\n";
echo rud('a%20b%26c'), "\n";
echo rue('~._-'), "\n";
echo ud('foo%2Bbar'), "\n";
echo rud('foo%2Bbar'), "\n";
