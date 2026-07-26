<?php
// Repro #23276 — date_create / date_create_immutable datetime:/timezone: named parameters
$ok = true;
foreach (['date_create' => 'DateTime', 'date_create_immutable' => 'DateTimeImmutable'] as $fn => $class) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    if (['datetime', 'timezone'] !== $names) {
        $ok = false;
        break;
    }
    $named = $fn(datetime: 'now');
    if (!($named instanceof $class)) {
        $ok = false;
        break;
    }
}
echo $ok ? "ok\n" : "fail\n";
