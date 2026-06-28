<?php

declare(strict_types=1);

// Repro for #13030 — parse_ini_string() double-quoted multiline values (php-src ini_scanner.l).
$ini = "x = \"line1\nline2\"\n";
$result = parse_ini_string($ini);
$expected = ['x' => "line1\nline2"];
if ($result !== $expected) {
    echo 'fail: parse_ini_string multiline quoted: got ';
    var_export($result);
    echo ' expected ';
    var_export($expected);
    echo "\n";
    exit(1);
}

$single = parse_ini_string('y = "hello"');
if ($single !== ['y' => 'hello']) {
    echo 'fail: parse_ini_string single-line quoted: got ';
    var_export($single);
    echo "\n";
    exit(1);
}

$section = parse_ini_string("[sec]\nz = \"a\nb\"", true);
if ($section !== ['sec' => ['z' => "a\nb"]]) {
    echo 'fail: parse_ini_string section multiline: got ';
    var_export($section);
    echo "\n";
    exit(1);
}

echo "ok\n";
