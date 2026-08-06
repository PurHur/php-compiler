--TEST--
stdlib compress.brotli:// stream wrapper round-trip (#28115, kjdev/php-ext-brotli)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsBrotli()) {
    die('skip brotli withheld on reference profile (#17563)');
}
--FILE--
<?php
$plain = 'hello-brotli-stream';
$f = sys_get_temp_dir().'/phpc_brotli_wrap_'.getmypid().'.br';
@unlink($f);
echo in_array('compress.brotli', stream_get_wrappers(), true) ? '1' : '0';
echo "\n";
$n = file_put_contents('compress.brotli://'.$f, $plain);
echo false === $n ? '0' : '1';
echo "\n";
$round = file_get_contents('compress.brotli://'.$f);
echo $round === $plain ? '1' : '0';
echo "\n";
$raw = file_get_contents($f);
$direct = brotli_compress($plain);
echo (false !== $raw && false !== $direct && $raw === $direct) ? '1' : '0';
echo "\n";
@unlink($f);
?>
--EXPECT--
1
1
1
1
