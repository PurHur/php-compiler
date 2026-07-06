<?php

declare(strict_types=1);

$core = [
    'grapheme_strlen',
    'grapheme_substr',
    'grapheme_strpos',
    'grapheme_extract',
    'grapheme_str_split',
];
foreach ($core as $name) {
    if (function_exists($name)) {
        echo "fail: function_exists true for {$name} without ext/intl on forward 8.4 profile\n";
        exit(1);
    }
}
$s = "a\xCC\x81b";
if (2 !== grapheme_strlen($s)) {
    echo "fail: grapheme_strlen\n";
    exit(1);
}
if ("a\xCC\x81" !== grapheme_substr($s, 0, 1)) {
    echo "fail: grapheme_substr\n";
    exit(1);
}
if (1 !== grapheme_strpos($s, 'b')) {
    echo "fail: grapheme_strpos\n";
    exit(1);
}
if ('a' . "\xCC\x81" !== grapheme_extract($s, 1)) {
    echo "fail: grapheme_extract\n";
    exit(1);
}
if (2 !== count(grapheme_str_split($s))) {
    echo "fail: grapheme_str_split\n";
    exit(1);
}
echo "ok\n";
