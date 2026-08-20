--TEST--
shell_exec/fstat/fpassthru/disk_* Zend stub names + named args (#23597)
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

dumpParams('shell_exec');
dumpParams('fstat');
dumpParams('fpassthru');
dumpParams('disk_free_space');
dumpParams('disk_total_space');

$out = shell_exec(command: 'printf named-shell');
echo 'shell=', $out === false || $out === null ? 'fail' : trim((string) $out), "\n";

$path = sys_get_temp_dir() . '/phpc_23597_' . getmypid() . '.txt';
file_put_contents($path, "fp-line\n");
$fp = fopen($path, 'r');
$st = fstat(stream: $fp);
echo 'fstat=', is_array($st) && isset($st['size']) && $st['size'] === 8 ? 'ok' : 'bad', "\n";
echo 'fpassthru=';
fpassthru(stream: $fp);
echo "\n";
fclose($fp);
@unlink($path);

echo 'dfs=', is_float(disk_free_space(directory: '/')) || is_int(disk_free_space(directory: '/')) ? 'ok' : 'bad', "\n";
echo 'dts=', is_float(disk_total_space(directory: '/')) || is_int(disk_total_space(directory: '/')) ? 'ok' : 'bad', "\n";

try {
    shell_exec(cmd: 'true');
    echo "legacy shell_exec cmd accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
try {
    $fp = fopen(__FILE__, 'r');
    fstat(fp: $fp);
    echo "legacy fstat fp accepted\n";
    fclose($fp);
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
try {
    disk_free_space(path: '/');
    echo "legacy disk_free_space path accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
shell_exec command
fstat stream
fpassthru stream
disk_free_space directory
disk_total_space directory
shell=named-shell
fstat=ok
fpassthru=fp-line

dfs=ok
dts=ok
Unknown named parameter $cmd
Unknown named parameter $fp
Unknown named parameter $path
