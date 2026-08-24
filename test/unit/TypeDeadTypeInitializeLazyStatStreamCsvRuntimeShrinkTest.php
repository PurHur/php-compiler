<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type::initialize always-on Stat/StreamGlobals/Gz/Bz2/Csv ensureLinked
 * (#34445 / peer #34439).
 *
 * Call sites link lazily so scripts that never touch those builtins skip NestedJIT
 * on the full load path (#32122 .1 mint class).
 */
final class TypeDeadTypeInitializeLazyStatStreamCsvRuntimeShrinkTest extends TestCase
{
    public function testTypeInitializeDropsEagerStatStreamCsvEnsureLinked(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#34445', $type);
        foreach ([
            'StatCache',
            'StatPath',
            'Stats',
            'StreamGlobals',
            'GzStreamIo',
            'Bz2StreamIo',
            'StringStreamCsv',
        ] as $class) {
            $this->assertDoesNotMatchRegularExpression(
                '/'.'(?<![A-Za-z0-9_])'.$class.'::ensureLinked\\(\\$this->context\\)/',
                $type,
                "Builtin\\Type::initialize must not eagerly {$class}::ensureLinked (#34445)"
            );
        }
        $this->assertStringContainsString(
            'StringTime::ensureLinked($this->context)',
            $type,
            'StringTime stays eager (#34445 / TimeRuntimeShrinkTest)'
        );
    }

    public function testCallSitesEnsureLinkBeforeLookup(): void
    {
        $checks = [
            'ext/standard/JitStat.php' => 'StatPathRuntime::ensureLinked',
            'ext/standard/JitStatPathKernel.php' => 'StatCacheRuntime::ensureLinked',
            'ext/standard/JitClearstatcache.php' => 'StatCacheRuntime::ensureLinked',
            'ext/stats/JitStats.php' => 'Stats::ensureLinked',
            'ext/standard/JitStreamIoKernel.php' => 'StreamGlobalsJit::implement',
            'ext/standard/JitStreamSyncKernel.php' => 'StreamGlobalsJit::implement',
            'ext/standard/JitGzopen.php' => 'GzStreamRuntime::ensureLinked',
            'ext/standard/JitGzfile.php' => 'GzStreamIo::ensureLinked',
            'ext/bz2/JitBz2open.php' => 'Bz2StreamRuntime::ensureLinked',
            'ext/bz2/JitBz2read.php' => 'Bz2StreamRuntime::ensureLinked',
            'ext/standard/JitStrGetcsv.php' => 'StringStrGetcsv::ensureLinked',
            'ext/standard/JitFgetcsv.php' => 'StringStrGetcsv::ensureLinked',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must link before use (#34445)');
        }
    }

    public function testNoNewRuntimeCForLazyStatStreamCsvAbis(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'file_exists.c',
            'is_file.c',
            'clearstatcache.c',
            'gzopen.c',
            'bzopen.c',
            'fgetcsv.c',
            'str_getcsv.c',
            'stream_globals.c',
        ] as $basename) {
            $this->assertFileDoesNotExist($runtimeDir.'/'.$basename, $basename.' must stay absent (#34445)');
        }
    }
}
