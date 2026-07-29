--TEST--
stdlib bzopen/bzread/bzwrite/bzclose stream round-trip (#17301)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\bz2\Bz2ExtensionPolicy::advertisesExtension()) {
    die('skip bz2 withheld (#11992/#25011)');
}
--ENV--
PHP_COMPILER_ENABLE_BZ2=1
--FILE--
<?php
$tmp = sys_get_temp_dir().'/bz2_stream_compliance_'.getmypid().'.bz2';
@unlink($tmp);
$fp = bzopen($tmp, 'w');
echo is_resource($fp) ? '1' : '0';
echo get_resource_type($fp) === 'bzip2' ? '1' : '0';
echo bzwrite($fp, 'abc') === 3 ? '1' : '0';
echo bzclose($fp) ? '1' : '0';
$fp2 = bzopen($tmp, 'r');
echo is_resource($fp2) ? '1' : '0';
echo bzread($fp2, 1024) === 'abc' ? '1' : '0';
echo bzclose($fp2) ? '1' : '0';
echo function_exists('bzopen') ? '1' : '0';
@unlink($tmp);
echo "\n";
?>
--EXPECT--
11111111
