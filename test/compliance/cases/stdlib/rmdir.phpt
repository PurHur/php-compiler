--TEST--
stdlib rmdir()
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/rmdir_fixture';
$one = $base . '/one';
if (mkdir($one, 0755)) {
    if (rmdir($one)) {
        if (is_dir($one)) {
            echo "bad\n";
        } else {
            echo "ok\n";
        }
    } else {
        echo "fail\n";
    }
} else {
    echo "mkfail\n";
}
if (rmdir($one)) {
    echo "badgone\n";
} else {
    echo "gone\n";
}
if (rmdir('/no/such/phpc-rmdir-path')) {
    echo "badnogone\n";
} else {
    echo "nogone\n";
}
--EXPECT--
ok
gone
nogone
