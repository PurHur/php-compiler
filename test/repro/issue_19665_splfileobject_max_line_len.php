<?php
$tmp = sys_get_temp_dir() . '/phpc_issue_19665_' . getmypid() . '.txt';
file_put_contents($tmp, "abcdefghij\n");
$o = new SplFileObject($tmp);
echo 'default=', var_export($o->getMaxLineLen(), true), "\n";
$o->setMaxLineLen(4);
echo 'get=', var_export($o->getMaxLineLen(), true), "\n";
$o->rewind();
echo 'line=', var_export($o->fgets(), true), "\n";
try {
    $o->setMaxLineLen(-1);
    echo "neg=ok\n";
} catch (ValueError $e) {
    echo 'neg=', $e->getMessage(), "\n";
}
unlink($tmp);
