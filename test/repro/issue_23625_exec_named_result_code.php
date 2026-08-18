<?php
/**
 * #23625 — exec/passthru/system Zend stub names (basic_functions.stub.php / exec.c).
 * InternalArgInfo still uses return_value instead of result_code.
 */
function dumpParams(string $fn): void
{
    $r = new ReflectionFunction($fn);
    $n = [];
    foreach ($r->getParameters() as $p) {
        $n[] = $p->getName();
    }
    echo $fn, ' ', implode(',', $n), "\n";
}

dumpParams('exec');
dumpParams('passthru');
dumpParams('system');

$rc = -1;
echo 'exec=', exec(command: 'printf hi', result_code: $rc), ' rc=', $rc, "\n";

$rc = -1;
passthru(command: 'true', result_code: $rc);
echo 'passthru_rc=', $rc, "\n";

$rc = -1;
system(command: 'true', result_code: $rc);
echo 'system_rc=', $rc, "\n";

try {
    exec(return_value: 0);
    echo "legacy exec return_value accepted\n";
} catch (Throwable $e) {
    echo 'legacy_exec=', $e->getMessage(), "\n";
}
try {
    passthru(return_value: 0);
    echo "legacy passthru return_value accepted\n";
} catch (Throwable $e) {
    echo 'legacy_passthru=', $e->getMessage(), "\n";
}
try {
    system(return_value: 0);
    echo "legacy system return_value accepted\n";
} catch (Throwable $e) {
    echo 'legacy_system=', $e->getMessage(), "\n";
}
