--TEST--
stdlib file_exists() JIT — TypeError for non-string path (#4907)
--FILE--
<?php
try {
    $unused = file_exists([]);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
$path = 'test/compliance/cases/stdlib/readfile_fixture/data.txt';
echo file_exists($path) ? "ok\n" : "missing\n";
--EXPECT--
TypeError: file_exists(): Argument #1 ($filename) must be of type string, array given
ok
