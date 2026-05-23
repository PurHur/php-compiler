--TEST--
stdlib rmdir()
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/rmdir_fixture';
$dir = $base . '/one';
if (!is_dir($base)) {
    mkdir($base, 0777, true);
}
if (mkdir($dir, 0755)) {
    if (rmdir($dir)) {
        if (!is_dir($dir)) {
            echo "ok\n";
        } else {
            echo "bad\n";
        }
    } else {
        echo "fail\n";
    }
} else {
    echo "setup\n";
}
if (rmdir($dir)) {
    echo "bad\n";
} else {
    echo "gone\n";
}
if (rmdir('/no/such/phpc-rmdir-path')) {
    echo "badgone\n";
} else {
    echo "nogone\n";
}
--EXPECT--
ok
gone
nogone
