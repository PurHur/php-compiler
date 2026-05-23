--TEST--
stdlib chmod()
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/chmod_fixture';
$path = $base . '/data.txt';
if (!is_dir($base)) {
    mkdir($base, 0777, true);
}
if (file_put_contents($path, 'x')) {
    if (chmod($path, 0644)) {
        echo "ok\n";
    } else {
        echo "fail\n";
    }
} else {
    echo "setup\n";
}
if (chmod('/no/such/phpc-chmod-path', 0644)) {
    echo "bad\n";
} else {
    echo "nogone\n";
}
--EXPECT--
ok
nogone
