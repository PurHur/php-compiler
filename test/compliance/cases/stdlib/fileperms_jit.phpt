--TEST--
JIT: fileperms() via stat st_mode
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/chmod_fixture/jit_data.txt';
if (file_put_contents($path, 'x') && chmod($path, 0600)) {
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
--EXPECT--
ok
