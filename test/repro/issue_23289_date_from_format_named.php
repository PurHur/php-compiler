<?php
// Repro #23289 — date_create_from_format / immutable_from_format / date_modify named params
date_default_timezone_set('UTC');
$ok = true;

$rf = new ReflectionFunction('date_create_from_format');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
if (['format', 'datetime', 'timezone'] !== $names) {
    $ok = false;
}

$rf = new ReflectionFunction('date_create_immutable_from_format');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
if (['format', 'datetime', 'timezone'] !== $names) {
    $ok = false;
}

$rf = new ReflectionFunction('date_modify');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
if (['object', 'modifier'] !== $names) {
    $ok = false;
}

$dt = date_create_from_format(format: 'Y-m-d', datetime: '2024-01-15');
if (!($dt instanceof DateTime) || '2024-01-15' !== $dt->format('Y-m-d')) {
    $ok = false;
}

$imm = date_create_immutable_from_format(format: 'Y-m-d', datetime: '2024-02-20');
if (!($imm instanceof DateTimeImmutable) || '2024-02-20' !== $imm->format('Y-m-d')) {
    $ok = false;
}

$mod = date_create('2024-01-15');
date_modify(object: $mod, modifier: '+1 day');
if ('2024-01-16' !== $mod->format('Y-m-d')) {
    $ok = false;
}

echo $ok ? "ok\n" : "fail\n";
