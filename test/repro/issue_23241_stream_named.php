<?php
// Repro #23241 — stream I/O Reflection + Zend stub named param `stream`
$checks = [];

$single = ['fclose', 'feof', 'fgetc', 'ftell', 'rewind', 'fflush'];
foreach ($single as $fn) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    $checks[] = ['stream'] === $names;
}

$rf = new ReflectionFunction('fseek');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
$checks[] = ['stream', 'offset', 'whence'] === $names;

$fp = fopen('php://memory', 'r+');
fwrite($fp, 'ab');
rewind(stream: $fp);
$checks[] = false === feof(stream: $fp);
$checks[] = 'a' === fgetc(stream: $fp);
$checks[] = 1 === ftell(stream: $fp);
$checks[] = 0 === fseek(stream: $fp, offset: 0, whence: SEEK_SET);
$checks[] = true === fflush(stream: $fp);
$checks[] = true === fclose(stream: $fp);

$legacyRejected = false;
try {
    $fp2 = fopen('php://memory', 'r');
    feof(fp: $fp2);
} catch (Error $e) {
    $legacyRejected = str_contains($e->getMessage(), 'Unknown named parameter $fp');
}
$checks[] = $legacyRejected;

$ok = true;
foreach ($checks as $c) {
    if (!$c) {
        $ok = false;
        break;
    }
}
echo $ok ? "ok\n" : "fail\n";
