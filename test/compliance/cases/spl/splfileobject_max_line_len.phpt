--TEST--
SPL SplFileObject getMaxLineLen/setMaxLineLen (#19665, ext/spl/spl_directory.c)
--FILE--
<?php
$tmp = sys_get_temp_dir() . '/phpc_sfo_maxlen_' . getmypid() . '.txt';
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
?>
--EXPECT--
default=0
get=4
line='abcd'
neg=SplFileObject::setMaxLineLen(): Argument #1 ($maxLength) must be greater than or equal to 0
