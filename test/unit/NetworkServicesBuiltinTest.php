<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** getprotobyname() / getservbyname() VM smoke (issue #3593). */
final class NetworkServicesBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
echo function_exists('getprotobyname') ? 'proto_yes' : 'proto_no', "\n";
echo function_exists('getservbyname') ? 'serv_yes' : 'serv_no', "\n";
$tcp = getprotobyname('tcp');
if (is_readable('/etc/protocols')) {
    echo $tcp === 6 ? "proto=6\n" : "proto_fail\n";
} else {
    echo false === $tcp ? "proto_false\n" : "proto_fail\n";
}
$http = getservbyname('http', 'tcp');
if (is_readable('/etc/services')) {
    echo $http === 80 ? "port=80\n" : "serv_fail\n";
} else {
    echo false === $http ? "serv_false\n" : "serv_fail\n";
}
$bad = @getprotobyname('not_a_real_protocol_xyz');
echo $bad === false ? "unknown_false\n" : "unknown_bad\n";
PHP;

    private const EXPECT_WITH_ETC = "proto_yes\nserv_yes\nproto=6\nport=80\nunknown_false\n";

    private const EXPECT_WITHOUT_ETC = "proto_yes\nserv_yes\nproto_false\nserv_false\nunknown_false\n";

    public function testVmMatchesPhpSubset(): void
    {
        $expect = is_readable('/etc/protocols') && is_readable('/etc/services')
            ? self::EXPECT_WITH_ETC
            : self::EXPECT_WITHOUT_ETC;
        $this->assertSame($expect, $this->runBin('bin/vm.php'));
    }

    private function runBin(string $bin): string
    {
        $repo = dirname(__DIR__, 2);
        $path = $repo.'/'.$bin;
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_net_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".self::CODE);
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(['php', $path, $tmp], $descriptor, $pipes, $repo, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($tmp);
        $this->assertSame(0, $exit, trim((string) $err));

        return (string) $out;
    }
}
