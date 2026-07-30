<?php
// Repro #24865 — stripcslashes Zend stub named parameter (string)
$checks = [];
$names = [];
foreach ((new ReflectionFunction('stripcslashes'))->getParameters() as $p) {
    $names[] = $p->getName();
}
$checks[] = ['string'] === $names;
try {
    $v = stripcslashes(string: "a\\nb");
    $checks[] = "a\nb" === $v;
} catch (Throwable $e) {
    $checks[] = false;
}
try {
    stripcslashes(str: "a\\nb");
    $checks[] = false;
} catch (Error $e) {
    $checks[] = str_contains($e->getMessage(), 'Unknown named parameter $str');
}
echo (!in_array(false, $checks, true)) ? "ok\n" : "fail\n";
