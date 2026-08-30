<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT instance property assign must write the property (#35874 leftover of #35863).
 *
 * @group llvm
 */
final class InstancePropAssign35874AotTest extends TestCase
{
    public function testInstancePropAssignAndTernaryGuardMatchZend(): void
    {
        $src = __DIR__.'/../repro/aot_instance_prop_assign_leftover_35863.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        foreach ([
            'str=c',
            'int=2',
            'unset=d',
            'ternary=null|prop=keep',
        ] as $needle) {
            $this->assertStringContainsString($needle, $zend, 'Zend: '.$needle);
            $this->assertStringContainsString($needle, $aot, 'AOT: '.$needle);
        }
        $this->assertSame($zend, $aot);
    }

    private function runPhp(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/instance_prop_assign_35874_'.getmypid();
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        putenv('PHP_COMPILER_HELPER_RUNTIME_O=0');
        exec($cmd.' 2>&1', $compOut, $compRc);
        $this->assertSame(0, $compRc, implode("\n", $compOut));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $out, $rc);
        @unlink($bin);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }
}
