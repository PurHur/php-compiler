<?php
// Repro #23260 — serialize/unserialize Zend stub names + named args (php-src basic_functions.stub.php)
$checks = [];

$namesOf = static function (string $fn): array {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }

    return $names;
};

$checks[] = ['value'] === $namesOf('serialize');
$checks[] = ['data', 'options'] === $namesOf('unserialize');

$ser = new ReflectionFunction('serialize');
$checks[] = 'mixed' === (string) $ser->getParameters()[0]->getType();
$checks[] = 'string' === (string) $ser->getReturnType();

$uns = new ReflectionFunction('unserialize');
$checks[] = 'string' === (string) $uns->getParameters()[0]->getType();
$checks[] = 'array' === (string) $uns->getParameters()[1]->getType();
$checks[] = $uns->getParameters()[1]->isOptional();
$checks[] = $uns->getParameters()[1]->isDefaultValueAvailable()
    && [] === $uns->getParameters()[1]->getDefaultValue();
$checks[] = 'mixed' === (string) $uns->getReturnType();

$checks[] = 'a:1:{i:0;i:1;}' === serialize(value: [1]);
$checks[] = 1 === unserialize(data: 'i:1;');
$checks[] = 7 === unserialize(data: 'i:7;', options: []);

$legacySerRejected = false;
try {
    serialize(variable: [1]);
} catch (Error $e) {
    $legacySerRejected = str_contains($e->getMessage(), 'Unknown named parameter $variable');
}
$checks[] = $legacySerRejected;

$legacyUnsRejected = false;
try {
    unserialize(variable_representation: 'i:1;');
} catch (Error $e) {
    $legacyUnsRejected = str_contains($e->getMessage(), 'Unknown named parameter $variable_representation');
}
$checks[] = $legacyUnsRejected;

$ok = true;
foreach ($checks as $c) {
    if (!$c) {
        $ok = false;
        break;
    }
}
echo $ok ? "ok\n" : "fail\n";
