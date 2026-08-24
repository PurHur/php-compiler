<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type::initialize always-on strtr / phpinfo / dir / fs / iterator
 * ensureLinked (#34433 / peer #34423).
 *
 * Call sites link lazily so scripts that never touch those builtins skip NestedJIT
 * on the full load path (#32122 .1 mint class).
 */
final class TypeDeadTypeInitializeLazyDirFsIteratorRuntimeShrinkTest extends TestCase
{
    public function testTypeInitializeDropsEagerDirFsIteratorEnsureLinked(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#34433', $type);
        foreach ([
            'StringStrtr::ensureLinked($this->context)',
            'StringPhpinfoRuntime::ensureLinked($this->context)',
            'StringDir::ensureLinked($this->context)',
            'DirectoryIteratorSnapshotRuntime::ensureLinked($this->context)',
            'GlobIteratorSnapshotRuntime::ensureLinked($this->context)',
            'SplFileObjectSnapshotRuntime::ensureLinked($this->context)',
            'StringFsGlob::ensureLinked($this->context)',
            'StringFsDir::ensureLinked($this->context)',
        ] as $call) {
            $this->assertStringNotContainsString(
                $call,
                $type,
                'Builtin\\Type::initialize must not eagerly '.$call.' (#34433)'
            );
        }
        $this->assertStringContainsString(
            'StringTime::ensureLinked($this->context)',
            $type,
            'StringTime stays eager (#34433 / TimeRuntimeShrinkTest)'
        );
    }

    public function testCallSitesEnsureLinkBeforeLookup(): void
    {
        $checks = [
            'ext/standard/JitStrtr.php' => 'StringStrtr::ensureLinked',
            'ext/standard/JitInfo.php' => 'StringPhpinfoRuntime::ensureLinked',
            'ext/standard/readdir.php' => 'StringDir::ensureLinked',
            'ext/standard/closedir.php' => 'StringDir::ensureLinked',
            'ext/standard/rewinddir.php' => 'StringDir::ensureLinked',
            'lib/VM/DirectoryIteratorJitHelper.php' => 'DirectoryIteratorSnapshotRuntime::ensureLinked',
            'lib/VM/GlobIteratorJitHelper.php' => 'GlobIteratorSnapshotRuntime::ensureLinked',
            'lib/JIT/Builtin/SplFileObjectSnapshotRuntime.php' => 'self::ensureLinked($context)',
            'ext/standard/glob_.php' => 'StringFsGlob::ensureLinked',
            'ext/standard/scandir.php' => 'StringFsGlob::ensureLinked',
            'ext/standard/JitIsUploadedFile.php' => 'StringFsDir::ensureLinked',
            'ext/standard/JitMoveUploadedFile.php' => 'StringFsDir::ensureLinked',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must link before use (#34433)');
        }
    }

    public function testNoNewRuntimeCForLazyDirFsIteratorAbis(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'phpc_strtr.c',
            'phpc_strtr_array.c',
            'phpinfo.c',
            'phpc_opendir.c',
            'phpc_readdir.c',
            'phpc_glob.c',
            'phpc_scandir.c',
            'phpc_directory_iterator_snapshot.c',
            'phpc_glob_iterator_snapshot.c',
            'phpc_splfileobject_lines.c',
            'phpc_is_uploaded_file.c',
            'phpc_move_uploaded_file.c',
        ] as $basename) {
            $this->assertFileDoesNotExist($runtimeDir.'/'.$basename, $basename.' must stay absent (#34433)');
        }
    }
}
