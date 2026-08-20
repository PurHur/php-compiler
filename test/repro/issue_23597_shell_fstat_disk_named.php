<?php
/**
 * #23597 — shell_exec/fstat/fpassthru/disk_* Zend stub names (file.stub.php / exec.c / filestat.c).
 * InternalArgInfo still uses cmd / fp / path.
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

dumpParams('shell_exec');
dumpParams('fstat');
dumpParams('fpassthru');
dumpParams('disk_free_space');
dumpParams('disk_total_space');

echo 'shell=', trim((string) shell_exec(command: 'printf hi')), "\n";

$path = sys_get_temp_dir() . '/phpc_23597_repro_' . getmypid() . '.txt';
file_put_contents($path, "x\n");
$fp = fopen($path, 'r');
$st = fstat(stream: $fp);
echo 'fstat=', is_array($st) ? 'ok' : 'bad', "\n";
fpassthru(stream: $fp);
echo "\n";
fclose($fp);
@unlink($path);

echo 'dfs=', (disk_free_space(directory: '/') > 0) ? 'ok' : 'bad', "\n";
echo 'dts=', (disk_total_space(directory: '/') > 0) ? 'ok' : 'bad', "\n";
