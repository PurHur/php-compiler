--TEST--
exec/passthru/system Zend stub names + named result_code (#23625, exec.c)
--FILE--
<?php
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
    echo $e->getMessage(), "\n";
}
try {
    passthru(return_value: 0);
    echo "legacy passthru return_value accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
try {
    system(return_value: 0);
    echo "legacy system return_value accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
exec command,output,result_code
passthru command,result_code
system command,result_code
exec=hi rc=0
passthru_rc=0
system_rc=0
Unknown named parameter $return_value
Unknown named parameter $return_value
Unknown named parameter $return_value
