<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type::initialize always-on StringPregMatch / StringXmlrpc / StringFormat /
 * Sscanf / StringPack / StringUnpack ensureLinked (#34357 / peer #34337).
 *
 * Call-site JitPreg* / JitXmlrpc / JitSprintf / JitPrintf / JitNumberFormat /
 * JitVsprintf / JitSscanf / PackJitRuntime / UnpackJitRuntime link lazily
 * (getNamedFunction first) so hello-world and other scripts that never touch
 * preg/format/pack/xmlrpc/sscanf builtins skip NestedJIT on the full load path
 * (#32122 .1 mint class).
 */
final class TypeDeadTypeInitializeLazyPregFormatPackRuntimeShrinkTest extends TestCase
{
    public function testTypeInitializeDropsEagerPregFormatPackEnsureLinked(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#34357', $type);
        foreach ([
            'StringPregMatch::ensureLinked($this->context)',
            'StringXmlrpc::ensureLinked($this->context)',
            'StringFormat::ensureLinked($this->context)',
            'Sscanf::ensureLinked($this->context)',
            'StringPack::ensureLinked($this->context)',
            'StringUnpack::ensureLinked($this->context)',
        ] as $call) {
            $this->assertStringNotContainsString(
                $call,
                $type,
                'Builtin\\Type::initialize must not eagerly '.$call.' (#34357)'
            );
        }
        $this->assertStringContainsString(
            'StringTime::ensureLinked($this->context)',
            $type,
            'StringTime stays eager (#34357 / TimeRuntimeShrinkTest)'
        );
    }

    public function testCallSitesEnsureLinkBeforeLookup(): void
    {
        $checks = [
            'ext/standard/JitPregMatch.php' => 'StringPregMatch::ensureLinked',
            'ext/standard/JitVsprintf.php' => 'StringFormat::ensureLinked',
            'ext/standard/JitSscanf.php' => 'Sscanf::ensureLinked',
            'lib/JIT/Builtin/PackJitRuntime.php' => 'StringPack::ensureLinked',
            'lib/JIT/Builtin/UnpackJitRuntime.php' => 'StringUnpack::ensureLinked',
            'ext/xmlrpc/JitXmlrpc.php' => 'StringXmlrpc::ensureEncodeLinked',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must link before use (#34357)');
        }
        $jitSprintf = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSprintf.php');
        $this->assertStringContainsString('StringFormat::implementIfDeclared', $jitSprintf);
        $jitXmlrpc = (string) file_get_contents(__DIR__.'/../../ext/xmlrpc/JitXmlrpc.php');
        $this->assertStringContainsString('StringXmlrpc::ensureDecodeLinked', $jitXmlrpc);
    }

    public function testNoNewRuntimeCForLazyPregFormatPackAbis(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'phpc_preg_match.c',
            'phpc_sprintf.c',
            'phpc_pack.c',
            'phpc_sscanf.c',
            'phpc_xmlrpc_encode.c',
        ] as $basename) {
            $this->assertFileDoesNotExist($runtimeDir.'/'.$basename, $basename.' must stay absent (#34357)');
        }
    }
}
