<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type::initialize always-on Stream* orchestrator ensureLinked (#34439 / peer #34433).
 *
 * Call sites link lazily so scripts that never touch stream builtins skip NestedJIT
 * on the full load path (#32122 .1 mint class).
 */
final class TypeDeadTypeInitializeLazyStreamRuntimeShrinkTest extends TestCase
{
    public function testTypeInitializeDropsEagerStreamEnsureLinked(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#34439', $type);
        foreach ([
            'StreamSync',
            'StreamIo',
            'StreamCaps',
            'StreamLifecycle',
            'StreamBuffer',
            'StreamMeta',
            'StreamRead',
            'StreamResource',
        ] as $class) {
            $this->assertDoesNotMatchRegularExpression(
                '/'.'(?<![A-Za-z0-9_])'.$class.'::ensureLinked\\(\\$this->context\\)/' ,
                $type,
                "Builtin\\Type::initialize must not eagerly {$class}::ensureLinked (#34439)"
            );
        }
        // StringTime lazy as of #34513 — see TypeDeadTypeInitializeLazyTimeEnvTriggerPendingRuntimeShrinkTest.
        // StreamGlobals lazy-linked in peer #34445 — no longer asserted here.
    }

    public function testCallSitesEnsureLinkBeforeLookup(): void
    {
        $checks = [
            'ext/standard/JitFsync.php' => 'StreamSync::ensureLinked',
            'ext/standard/JitFdatasync.php' => 'StreamSync::ensureLinked',
            'ext/standard/JitFopen.php' => 'StreamIoRuntime::ensureLinkedForUserScriptLowering',
            'ext/standard/JitFwrite.php' => 'StreamIoRuntime::ensureLinkedForUserScriptLowering',
            'ext/standard/JitStreamIsLocal.php' => 'StreamCaps::ensureLinked',
            'ext/standard/JitStreamIsatty.php' => 'StreamCaps::ensureLinked',
            'ext/standard/JitFclose.php' => 'StreamLifecycleRuntime::ensureLinkedForUserScriptLowering',
            'ext/standard/JitFeof.php' => 'StreamLifecycleRuntime::ensureLinkedForUserScriptLowering',
            'ext/standard/JitStreamSetReadBuffer.php' => 'StreamBufferRuntime::ensureLinked',
            'ext/standard/JitStreamGetMetaData.php' => 'StreamMeta::ensureLinked',
            'ext/standard/JitFgetc.php' => 'StreamReadRuntime::ensureLinked',
            'ext/standard/JitGetResourceType.php' => 'StreamResource::ensureLinked',
            'ext/standard/JitGetResources.php' => 'StreamResource::ensureLinked',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must link before use (#34439)');
        }
    }

    public function testNoNewRuntimeCForLazyStreamAbis(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'fopen.c',
            'fwrite.c',
            'fread.c',
            'fclose.c',
            'feof.c',
            'fsync.c',
            'fdatasync.c',
            'stream_supports.c',
            'stream_isatty.c',
            'get_resource_type.c',
        ] as $basename) {
            $this->assertFileDoesNotExist($runtimeDir.'/'.$basename, $basename.' must stay absent (#34439)');
        }
    }
}
