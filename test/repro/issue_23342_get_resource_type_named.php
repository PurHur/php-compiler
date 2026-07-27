<?php
// Repro #23342 — get_resource_type resource: named parameter (Zend stub name)
$rf = new ReflectionFunction('get_resource_type');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
$path = sys_get_temp_dir() . '/phpc_issue_23342_' . getmypid() . '.txt';
file_put_contents($path, "x\n");
$f = fopen($path, 'r');
$named = get_resource_type(resource: $f);
$positional = get_resource_type($f);
fclose($f);
@unlink($path);
$resRejected = false;
try {
    // Legacy InternalArgInfo name — Zend rejects $res
    $dummy = fopen('php://memory', 'r');
    get_resource_type(res: $dummy);
    fclose($dummy);
} catch (Throwable $e) {
    $resRejected = str_contains($e->getMessage(), 'Unknown named parameter $res');
}
$ok = ['resource'] === $names
    && 'stream' === $named
    && $named === $positional
    && $resRejected;
echo $ok ? "ok\n" : "fail\n";
