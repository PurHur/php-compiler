--TEST--
stdlib bzerrno/bzerror/bzerrstr/bzflush forward 8.4 after bzopen (#22344, ext/bz2/bz2.c)
--ENV--
PHP_COMPILER_ENABLE_BZ2=1
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!\PHPCompiler\ext\bz2\Bz2ExtensionPolicy::advertisesExtension()) {
    die('skip bz2 withheld (#11992/#25011)');
}
--FILE--
<?php
declare(strict_types=1);
foreach (['bzcompress', 'bzopen', 'bzerrno', 'bzerror', 'bzerrstr', 'bzflush'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'no', "\n";
}
$tmp = sys_get_temp_dir().'/bz_err_'.getmypid().'.bz2';
$fp = bzopen($tmp, 'w');
if (false === $fp) {
    echo "open-fail\n";
    exit(1);
}
echo 'flush=', bzflush($fp) ? '1' : '0', "\n";
echo 'errno=', bzerrno($fp), "\n";
echo 'errstr=', bzerrstr($fp), "\n";
$bag = bzerror($fp);
echo 'bag=', $bag['errno'], ',', $bag['errstr'], "\n";
bzwrite($fp, 'hello');
@bzread($fp, 1);
echo 'seq=', bzerrno($fp), ',', bzerrstr($fp), "\n";
bzclose($fp);
@unlink($tmp);
?>
--EXPECT--
bzcompress=yes
bzopen=yes
bzerrno=yes
bzerror=yes
bzerrstr=yes
bzflush=yes
flush=1
errno=0
errstr=OK
bag=0,OK
seq=-1,SEQUENCE_ERROR
