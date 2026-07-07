--TEST--
stdlib bzopen/bzread/bzwrite/bzclose stream round-trip (#17301)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsBz2()) {
    die('skip bz2 withheld on reference profile (#11992)');
}
--FILE--
<?php
$tmp = sys_get_temp_dir().'/phpc_bz2_stream_'.getmypid().'.bz2';
@unlink($tmp);
$plain = 'abc stream';
$fp = bzopen($tmp, 'w');
echo is_resource($fp) ? '1' : '0';
echo bzwrite($fp, $plain) === strlen($plain) ? '1' : '0';
echo bzclose($fp) ? '1' : '0';
$fp = bzopen($tmp, 'r');
echo is_resource($fp) ? '1' : '0';
echo bzread($fp, 4096) === $plain ? '1' : '0';
echo bzclose($fp) ? '1' : '0';
@unlink($tmp);
echo "\n";
?>
--EXPECT--
111111
