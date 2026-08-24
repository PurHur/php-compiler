<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type::initialize always-on StringStripTags / StringConvertUu / StringQuotPrint /
 * StringUtf8Latin1 / StringUtf8Runtime ensureLinked (#34414 / peer #34384).
 *
 * Call sites link lazily so scripts that never touch those builtins skip NestedJIT
 * on the full load path (#32122 .1 mint class).
 */
final class TypeDeadTypeInitializeLazyStripUuUtf8RuntimeShrinkTest extends TestCase
{
    public function testTypeInitializeDropsEagerStripUuUtf8EnsureLinked(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#34414', $type);
        foreach ([
            'StringStripTags::ensureLinked($this->context)',
            'StringConvertUu::ensureLinked($this->context)',
            'StringQuotPrint::ensureLinked($this->context)',
            'StringUtf8Latin1::ensureLinked($this->context)',
            'StringUtf8Runtime::ensureLinked($this->context)',
        ] as $call) {
            $this->assertStringNotContainsString(
                $call,
                $type,
                'Builtin\\Type::initialize must not eagerly '.$call.' (#34414)'
            );
        }
        $this->assertStringContainsString(
            'StringTime::ensureLinked($this->context)',
            $type,
            'StringTime stays eager (#34414 / TimeRuntimeShrinkTest)'
        );
    }

    public function testCallSitesEnsureLinkBeforeLookup(): void
    {
        $checks = [
            'ext/standard/strip_tags.php' => 'StringStripTags::ensureLinked',
            'ext/standard/JitStripTags.php' => 'StringStripTags::ensureLinked',
            'ext/standard/JitConvertUuencode.php' => 'StringConvertUu::ensureLinked',
            'ext/standard/JitConvertUudecode.php' => 'StringConvertUu::ensureLinked',
            'ext/standard/JitQuotedPrintableEncode.php' => 'StringQuotPrint::ensureLinked',
            'ext/standard/JitQuotedPrintableDecode.php' => 'StringQuotPrint::ensureLinked',
            'ext/standard/JitUtf8Latin1.php' => 'StringUtf8Latin1::ensureLinked',
            'ext/mbstring/JitMbStrlen.php' => 'StringUtf8Runtime::ensureLinked',
            'lib/JIT/Builtin/StringUtf8Runtime.php' => 'self::ensureLinked($context)',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must link before use (#34414)');
        }
    }

    public function testNoNewRuntimeCForLazyStripUuUtf8Abis(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'phpc_strip_tags.c',
            'phpc_convert_uu.c',
            'phpc_quoted_printable.c',
            'phpc_utf8_encode.c',
            'phpc_utf8_strlen.c',
        ] as $basename) {
            $this->assertFileDoesNotExist($runtimeDir.'/'.$basename, $basename.' must stay absent (#34414)');
        }
    }
}
