<?php

declare(strict_types=1);

$typed = parse_ini_string("n=42\nf=3.14\nb=1", false, INI_SCANNER_TYPED);
$expectedTyped = ['n' => 42, 'f' => 3.14, 'b' => 1];
if ($typed !== $expectedTyped) {
    echo "fail: typed\n";
    var_export($typed);
    echo "\n";
    exit(1);
}

$rawParsed = parse_ini_string("t=true\nv=hello", false, INI_SCANNER_RAW);
$expectedRaw = ['t' => 'true', 'v' => 'hello'];
if ($rawParsed !== $expectedRaw) {
    echo "fail: raw\n";
    var_export($rawParsed);
    echo "\n";
    exit(1);
}

echo "ok\n";
