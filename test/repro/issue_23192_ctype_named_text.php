<?php
// Repro #23192 — ctype_* Zend stub $text named params
$checks = [];

$namesOf = static function (string $fn): array {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    return $names;
};

$checks[] = ['text'] === $namesOf('ctype_digit');
$checks[] = true === ctype_digit(text: '123');
$checks[] = true === ctype_alnum(text: 'A1');
$checks[] = true === ctype_alpha(text: 'Ab');
$checks[] = true === ctype_space(text: " \t");
$checks[] = true === ctype_xdigit(text: 'aF0');

foreach ([
    'ctype_alnum', 'ctype_alpha', 'ctype_cntrl', 'ctype_digit', 'ctype_graph',
    'ctype_lower', 'ctype_print', 'ctype_punct', 'ctype_space', 'ctype_upper',
    'ctype_xdigit',
] as $fn) {
    $checks[] = ['text'] === $namesOf($fn);
}

$cRejected = false;
try {
    ctype_digit(c: '123');
} catch (Error $e) {
    $cRejected = str_contains($e->getMessage(), 'Unknown named parameter $c');
}
$checks[] = $cRejected;

$ok = true;
foreach ($checks as $c) {
    if (!$c) {
        $ok = false;
        break;
    }
}
echo $ok ? "ok\n" : "fail\n";
