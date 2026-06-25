--TEST--
stdlib fopen() optional use_include_path and context args (#11493, ext/standard/streamsfuncs.c)
--FILE--
<?php
$h = fopen('php://memory', 'r+', false);
echo is_resource($h) ? "optional\n" : "optional-bad\n";
if (is_resource($h)) {
    fclose($h);
}
$ctx = stream_context_create([]);
$h2 = fopen('php://memory', 'r+', false, $ctx);
echo is_resource($h2) ? "context\n" : "context-bad\n";
if (is_resource($h2)) {
    fclose($h2);
}
$h3 = fopen(filename: 'php://memory', mode: 'r+', use_include_path: false);
echo is_resource($h3) ? "named\n" : "named-bad\n";
if (is_resource($h3)) {
    fclose($h3);
}
--EXPECT--
optional
context
named
