<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * xmlrpc_server_* dispatch (#27879, php-src ext/xmlrpc/xmlrpc-epi-php.c).
 */
final class XmlrpcServerDispatchVMTest extends TestCase
{
    public function test_server_register_call_round_trip(): void
    {
        $root = dirname(__DIR__, 2);
        $script = sys_get_temp_dir().'/phpc_xmlrpc_server_'.getmypid().'.php';
        file_put_contents($script, <<<'PHP'
<?php
declare(strict_types=1);
foreach ([
    'xmlrpc_server_create',
    'xmlrpc_server_register_method',
    'xmlrpc_server_call_method',
    'xmlrpc_server_destroy',
    'xmlrpc_parse_method_descriptions',
] as $f) {
    if (!function_exists($f)) {
        fwrite(STDERR, "missing $f\n");
        exit(2);
    }
}
$s = xmlrpc_server_create();
xmlrpc_server_register_method($s, 'add', function ($method, $params) {
    return $params[0] + $params[1];
});
$req = xmlrpc_encode_request('add', [2, 3]);
$out = xmlrpc_server_call_method($s, $req, null);
$method = '';
$decoded = xmlrpc_decode_request($out, $method);
echo 'sum=', (string) $decoded, "\n";
echo 'destroy=', xmlrpc_server_destroy($s) ? 'Y' : 'N', "\n";
PHP);

        $cmd = [PHP_BINARY, '-d', 'display_errors=1', $root.'/bin/vm.php', $script];
        $proc = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            array_merge($_ENV, ['PHP_COMPILER_PROFILE' => '8.4'])
        );
        $this->assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($script);

        $this->assertSame(0, $exit, trim((string) $stderr)."\n".(string) $stdout);
        $this->assertSame("sum=5\ndestroy=Y\n", $stdout, trim((string) $stderr));
    }
}
