--TEST--
stdlib chgrp() and lchgrp()
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/chmod_fixture';
$path = $base . '/chgrp_data.txt';
if (!is_dir($base)) {
    mkdir($base, 0777, true);
}
if (file_put_contents($path, 'x')) {
    $st = stat($path);
    $gid = (int) ($st['gid'] ?? 0);
    if (chgrp($path, $gid)) {
        echo "ok\n";
    } else {
        echo "fail\n";
    }
    $link = $path . '_lnk';
    if (symlink($path, $link)) {
        if (lchgrp($link, $gid)) {
            echo "lnk\n";
        } else {
            echo "lnkf\n";
        }
        unlink($link);
    }
} else {
    echo "setup\n";
}
if (chgrp('/no/such/phpc-chgrp-path', 0)) {
    echo "bad\n";
} else {
    echo "nogone\n";
}
--EXPECT--
ok
lnk
nogone
