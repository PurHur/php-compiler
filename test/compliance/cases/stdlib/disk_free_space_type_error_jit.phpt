--TEST--
stdlib disk_*_space() JIT — TypeError for non-string path (#4915, ext/standard/filestat.c)
--JIT--
--FILE--
<?php
foreach (['disk_free_space', 'diskfreespace', 'disk_total_space', 'disktotalspace'] as $fn) {
    try {
        $unused = $fn([]);
        echo $fn, " uncaught\n";
    } catch (Throwable $e) {
        echo $fn, ': ', $e::class, "\n";
    }
}
try {
    $unused = disk_free_space(new stdClass());
    echo "object uncaught\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
$path = sys_get_temp_dir();
echo disk_free_space($path) !== false ? "ok\n" : "fail\n";
echo disk_free_space(null) !== false ? "null_ok\n" : "null_fail\n";
--EXPECT--
disk_free_space: TypeError
diskfreespace: TypeError
disk_total_space: TypeError
disktotalspace: TypeError
TypeError: disk_free_space(): Argument #1 ($directory) must be of type string, stdClass given
ok
null_ok
