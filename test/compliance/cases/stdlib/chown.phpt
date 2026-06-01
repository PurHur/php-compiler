--TEST--
stdlib chown() and lchown()
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/chmod_fixture';
$path = $base . '/chown_data.txt';
if (!is_dir($base)) {
    mkdir($base, 0777, true);
}
if (file_put_contents($path, 'x')) {
    $st = stat($path);
    $uid = (int) ($st['uid'] ?? 0);
    if (chown($path, $uid)) {
        echo "ok\n";
    } else {
        echo "fail\n";
    }
    $link = $path . '_lnk';
    if (symlink($path, $link)) {
        if (lchown($link, $uid)) {
            echo "lnk\n";
        } else {
            echo "lnkf\n";
        }
        unlink($link);
    }
} else {
    echo "setup\n";
}
if (chown('/no/such/phpc-chown-path', 0)) {
    echo "bad\n";
} else {
    echo "nogone\n";
}
--EXPECT--
ok
lnk
nogone
