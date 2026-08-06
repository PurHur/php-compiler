<?php
/** Issue #28115 — compress.brotli:// stream wrapper when brotli advertised. */
$plain = 'hello-brotli-stream-wrapper';
$f = sys_get_temp_dir().'/phpc_brotli_stream_'.getmypid().'.br';
@unlink($f);

echo 'ext=', extension_loaded('brotli') ? '1' : '0', PHP_EOL;
echo 'wrapper=', in_array('compress.brotli', stream_get_wrappers(), true) ? '1' : '0', PHP_EOL;

$n = file_put_contents('compress.brotli://'.$f, $plain);
echo 'put=', false === $n ? '0' : '1', PHP_EOL;
$round = file_get_contents('compress.brotli://'.$f);
echo 'get=', $round === $plain ? '1' : '0', PHP_EOL;
$raw = @file_get_contents($f);
$direct = brotli_compress($plain);
echo 'raw_match=', (false !== $raw && false !== $direct && $raw === $direct) ? '1' : '0', PHP_EOL;
@unlink($f);
