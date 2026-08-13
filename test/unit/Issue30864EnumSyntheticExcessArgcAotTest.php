<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: Enum::cases/from/tryFrom excess/missing argc → ArgumentCountError (#30864).
 *
 * php-src: Zend/zend_enum.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30864EnumSyntheticExcessArgcAotTest extends TestCase
{
    public function testAotExcessAndMissingArgcCatchableUnderTry(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/enum_synthetic_excess_argc_30864.php';
        $bin = sys_get_temp_dir().'/phpc_30864_ex_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $compileOut = array_values(array_filter(
            $compileOut,
            static fn (string $line): bool => !str_contains($line, 'Deprecated:')
        ));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.$i.': '.implode("\n", $runOut));
                $this->assertSame(
                    "ArgumentCountError: E::cases() expects exactly 0 arguments, 1 given\n"
                    ."ArgumentCountError: E::from() expects exactly 1 argument, 2 given\n"
                    ."ArgumentCountError: E::tryFrom() expects exactly 1 argument, 2 given\n"
                    ."ArgumentCountError: E::from() expects exactly 1 argument, 0 given\n"
                    ."ArgumentCountError: E::tryFrom() expects exactly 1 argument, 0 given\n"
                    ."ok=A,1,NULL\n",
                    implode("\n", $runOut)."\n"
                );
            }
        } finally {
            @unlink($bin);
        }
    }
}
