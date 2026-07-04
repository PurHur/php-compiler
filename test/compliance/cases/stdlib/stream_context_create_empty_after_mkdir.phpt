--TEST--
stdlib stream_context_create([]) after @mkdir — empty options accepted (#16205, ext/standard/streams.c)
--FILE--
<?php
declare(strict_types=1);

$dir = sys_get_temp_dir().'/phpc_stream_ctx_compliance_'.getmypid();
@mkdir($dir);
$from = $dir.'/a.txt';
$to = $dir.'/b.txt';
file_put_contents($from, 'x');
try {
    $ctx = stream_context_create([]);
    $ok = copy($from, $to, $ctx);
    echo $ok ? 'ok' : 'fail', "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
@unlink($to);
@unlink($from);
@rmdir($dir);
--EXPECT--
ok
