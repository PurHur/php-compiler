<?php
// Repro #25590 — fgetcsv/str_getcsv reject named delimiter (ext/standard/file.stub.php)
foreach (['delimiter', 'separator'] as $name) {
    try {
        $args = ['string' => 'a;b', $name => ';'];
        $r = str_getcsv(...$args);
        echo "str_getcsv $name=";
        var_export($r);
        echo "\n";
    } catch (Throwable $e) {
        echo 'str_getcsv ', $name, '=', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
$tmp = tempnam(sys_get_temp_dir(), 'csv');
file_put_contents($tmp, "a;b\n");
$fp = fopen($tmp, 'r');
foreach (['delimiter', 'separator'] as $name) {
    rewind($fp);
    try {
        $args = ['stream' => $fp, 'length' => 0, $name => ';'];
        $r = fgetcsv(...$args);
        echo "fgetcsv $name=";
        var_export($r);
        echo "\n";
    } catch (Throwable $e) {
        echo 'fgetcsv ', $name, '=', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
fclose($fp);
unlink($tmp);
