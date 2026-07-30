--TEST--
stdlib scandir Reflection/named directory (#23448, ext/standard/dir.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('scandir');
echo 'names=';
foreach ($r->getParameters() as $p) {
    echo $p->getName();
    if ($p->isOptional()) {
        echo '=';
        if ($p->isDefaultValueAvailable()) {
            echo var_export($p->getDefaultValue(), true);
        } else {
            echo '?';
        }
    }
    echo ',';
}
echo "\n";
$dir = sys_get_temp_dir();
try {
    $a = scandir(directory: $dir);
    echo 'directory=', is_array($a) ? 'ok' : 'bad', "\n";
} catch (Throwable $e) {
    echo 'directory ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $a = scandir(dir: $dir);
    echo 'dir=', is_array($a) ? 'ok' : 'bad', "\n";
} catch (Throwable $e) {
    echo 'dir ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
$pos = scandir($dir);
echo 'positional=', is_array($pos) ? 'ok' : 'bad', "\n";
?>
--EXPECT--
names=directory,sorting_order=0,context=NULL,
directory=ok
dir ERR=Error: Unknown named parameter $dir
positional=ok
