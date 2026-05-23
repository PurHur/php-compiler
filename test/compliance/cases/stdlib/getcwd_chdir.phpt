--TEST--
stdlib getcwd() and chdir()
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/getcwd_chdir_fixture';
if (!is_dir($base)) {
    mkdir($base, 0777, true);
}
$start = getcwd();
if (!is_string($start)) {
    echo "nocwd\n";
    exit;
}
echo "start\n";
if (chdir($base)) {
    $here = getcwd();
    if (is_string($here) && basename($here) === 'getcwd_chdir_fixture') {
        echo "subdir\n";
    } else {
        echo "badhere\n";
    }
    if (chdir($start)) {
        echo "back\n";
    } else {
        echo "noback\n";
    }
} else {
    echo "nochdir\n";
}
if (chdir('/no/such/phpc-getcwd-chdir-path')) {
    echo "badchdir\n";
} else {
    echo "nogone\n";
}
--EXPECT--
start
subdir
back
nogone
