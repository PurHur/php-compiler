<?php
// Repro #23224 — trim/ltrim/rtrim Reflection is string/characters only; reject $mode
$namesOk = true;
foreach (['trim', 'ltrim', 'rtrim'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    if (['string', 'characters'] !== $names) {
        $namesOk = false;
    }
}
$namedOk = 'x' === trim(string: ' x ', characters: ' ');
$modeRejected = false;
try {
    trim(string: ' x ', mode: 1);
} catch (Throwable $e) {
    $modeRejected = str_contains($e->getMessage(), 'Unknown named parameter $mode');
}
echo ($namesOk && $namedOk && $modeRejected) ? "ok\n" : "fail\n";
