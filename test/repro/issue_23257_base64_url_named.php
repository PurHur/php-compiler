<?php
// Repro #23257 — base64_encode/urlencode/urldecode/rawurlencode/rawurldecode Zend stub named parameter (string)
$ok = true;
$cases = [
    'base64_encode' => ['ab', 'YWI='],
    'urlencode' => ['a b', 'a+b'],
    'urldecode' => ['a+b', 'a b'],
    'rawurlencode' => ['a b', 'a%20b'],
    'rawurldecode' => ['a%20b', 'a b'],
];
foreach ($cases as $fn => [$input, $expected]) {
    $names = [];
    foreach ((new ReflectionFunction($fn))->getParameters() as $p) {
        $names[] = $p->getName();
    }
    if (['string'] !== $names) {
        $ok = false;
    }
    $got = $fn(string: $input);
    if ($got !== $expected) {
        $ok = false;
    }
    if ($got !== $fn($input)) {
        $ok = false;
    }
    try {
        $fn(str: $input);
        $ok = false;
    } catch (Error $e) {
        if (!str_contains($e->getMessage(), 'Unknown named parameter $str')) {
            $ok = false;
        }
    }
}
echo $ok ? "ok\n" : "fail\n";
