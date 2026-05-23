--TEST--
stdlib fileperms()
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/chmod_fixture';
$path = $base . '/data.txt';
if (!is_dir($base)) {
    mkdir($base, 0777, true);
}
if (file_put_contents($path, 'x') && chmod($path, 0644)) {
    $p1 = fileperms($path);
    $p2 = fileperms($path);
    if ($p1 === false || $p2 === false || $p1 !== $p2) {
        echo 'fail', "\n";
    } else {
        echo 'ok', "\n";
    }
} else {
    echo 'setup', "\n";
}
if (fileperms('/no/such/phpc-fileperms-path')) {
    echo 'bad', "\n";
} else {
    echo 'gone', "\n";
}
--EXPECT--
ok
gone
