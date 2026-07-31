--TEST--
ftok named arguments + Reflection (VM, issue #26117)
--FILE--
<?php
$path = tempnam(sys_get_temp_dir(), 'ftok_named');
$names = [];
foreach ((new ReflectionFunction('ftok'))->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";
$key = ftok(filename: $path, project_id: 'a');
echo is_int($key) ? "int\n" : "not_int\n";
echo (ftok($path, 'a') === $key) ? "match\n" : "mismatch\n";
try {
    ftok(pathname: $path, proj: 'a');
    echo "legacy accepted\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
@unlink($path);
--EXPECT--
filename,project_id
int
match
Error
