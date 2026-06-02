--TEST--
AOT: chgrp() sets group via libc chgrp(2)
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/chmod_fixture/chgrp_aot_data.txt';
if (file_put_contents($path, 'x')) {
    $st = stat($path);
    $gid = (int) ($st['gid'] ?? 0);
    if (chgrp($path, $gid)) {
        echo "ok\n";
    } else {
        echo "fail\n";
    }
} else {
    echo "setup\n";
}
--EXPECT--
ok
