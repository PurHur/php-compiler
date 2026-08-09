<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * wddx_packet_start / wddx_add_vars / wddx_packet_end (#27858, pecl-text-wddx).
 *
 * Dedicated suite so --ENV-- PROFILE=8.4 is applied via bin/vm.php (VMTest data-provider
 * path is not required for this guard).
 */
final class WddxPacketBuildersVMTest extends TestCase
{
    public function test_packet_builders_round_trip_matches_serialize_vars(): void
    {
        $root = dirname(__DIR__, 2);
        $script = sys_get_temp_dir().'/phpc_wddx_packet_builders_'.getmypid().'.php';
        file_put_contents($script, <<<'PHP'
<?php
declare(strict_types=1);
$a = 1;
$b = 'two';
$viaSerialize = wddx_deserialize(wddx_serialize_vars('a', 'b'));
$packet = wddx_packet_start();
echo is_resource($packet) ? '1' : '0';
echo wddx_add_vars($packet, 'a', 'b') ? '1' : '0';
$xml = wddx_packet_end($packet);
echo is_string($xml) ? '1' : '0';
echo is_resource($packet) ? '0' : '1';
$viaPacket = wddx_deserialize($xml);
echo is_array($viaPacket) && $viaPacket === $viaSerialize ? '1' : '0';
echo function_exists('wddx_packet_start') ? '1' : '0';
echo function_exists('wddx_add_vars') ? '1' : '0';
echo function_exists('wddx_packet_end') ? '1' : '0';
echo "\n";
PHP);

        $cmd = [
            PHP_BINARY,
            '-d', 'display_errors=1',
            '-d', 'error_reporting=E_ALL',
            $root.'/bin/vm.php',
            $script,
        ];
        $proc = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            array_merge($_ENV, [
                'PHP_COMPILER_PROFILE' => '8.4',
            ])
        );
        $this->assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($script);

        $this->assertSame(0, $exit, trim((string) $stderr)."\n".(string) $stdout);
        $this->assertSame("11111111\n", $stdout, trim((string) $stderr));
    }
}
