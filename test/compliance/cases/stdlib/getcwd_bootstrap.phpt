--TEST--
stdlib getcwd() bootstrap — libc path without host getcwd delegation (#5044)
--FILE--
<?php
$cwd = getcwd();
if (!is_string($cwd) || $cwd === '') {
    echo "fail\n";
    exit;
}
echo "ok\n";
$base = 'test/compliance/cases/stdlib/getcwd_chdir_fixture/bootstrap_sub';
if (!is_dir($base)) {
    mkdir($base, 0777, true);
}
if (chdir($base)) {
    $here = getcwd();
    if (is_string($here) && basename($here) === 'bootstrap_sub') {
        echo "chdir\n";
    } else {
        echo "bad\n";
    }
    chdir($cwd);
} else {
    echo "nochdir\n";
}
--EXPECT--
ok
chdir
