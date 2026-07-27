<?php
// Repro #23258 — putenv assignment: named parameter (Zend stub name)
$rf = new ReflectionFunction('putenv');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
$okNamed = putenv(assignment: 'PHPC_ISSUE_23258=1');
$val = getenv('PHPC_ISSUE_23258');
putenv('PHPC_ISSUE_23258');
$settingRejected = false;
try {
    putenv(setting: 'PHPC_ISSUE_23258=1');
} catch (Throwable $e) {
    $settingRejected = str_contains($e->getMessage(), 'Unknown named parameter $setting');
}
$ok = ['assignment'] === $names
    && true === $okNamed
    && '1' === $val
    && $settingRejected;
echo $ok ? "ok\n" : "fail\n";
