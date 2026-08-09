--TEST--
stdlib stream_context_set_options advertised on PROFILE=8.3 (#29083)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsStreamContextSetOptions()) {
    die('skip stream_context_set_options needs PROFILE≥8.3');
}
--FILE--
<?php
declare(strict_types=1);

echo 'exists=', function_exists('stream_context_set_options') ? '1' : '0', "\n";
$ctx = stream_context_create();
echo 'set=', stream_context_set_options($ctx, ['http' => ['method' => 'GET', 'timeout' => 1]]) ? '1' : '0', "\n";
$opts = stream_context_get_options($ctx);
echo 'method=', $opts['http']['method'] ?? '?', "\n";
echo 'timeout=', (string) ($opts['http']['timeout'] ?? '?'), "\n";
--EXPECT--
exists=1
set=1
method=GET
timeout=1
