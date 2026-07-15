--TEST--
SPL SplFileObject / SplFileInfo — class surface, RecursiveIterator, ctor errors (#6393)
--FILE--
<?php
var_export(class_exists('SplFileInfo', false));
echo "\n";
var_export(class_exists('SplFileObject', false));
echo "\n";

$tmp = tempnam(sys_get_temp_dir(), 'spl');
file_put_contents($tmp, "line1\nline2\n");
$fo = new SplFileObject($tmp);
echo $fo->fgets();
var_export($fo instanceof RecursiveIterator);
echo "\n";
var_export($fo instanceof SeekableIterator);
echo "\n";
var_export($fo->hasChildren());
echo "\n";
var_export($fo->getChildren());
echo "\n";
$ifaces = class_implements($fo);
echo isset($ifaces['RecursiveIterator']) ? "has-RI\n" : "missing-RI\n";
echo isset($ifaces['SeekableIterator']) ? "has-SI\n" : "missing-SI\n";

try {
    new SplFileObject('/no/such/path/xxx');
    echo "badpath-ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo (str_contains($e->getMessage(), 'No such file or directory') ? "badpath-msg\n" : "badpath-other\n");
}

try {
    new SplFileObject($tmp, 'zz');
    echo "badmode-ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo (str_contains($e->getMessage(), "is not a valid mode for fopen") ? "badmode-msg\n" : "badmode-other\n");
}

@unlink($tmp);
--EXPECT--
true
true
line1
true
true
false
NULL
has-RI
has-SI
RuntimeException
badpath-msg
RuntimeException
badmode-msg
