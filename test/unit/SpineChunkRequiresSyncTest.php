<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** spine-chunk-*-requires.txt cover ext/ds probe stubs (#36155). */
final class SpineChunkRequiresSyncTest extends TestCase
{
    public function testCoreRequiresCoverCompilerVersionAndVmHelpers(): void
    {
        $root = dirname(__DIR__, 2);
        $requires = $this->readRequires($root.'/script/spine-chunk-core-requires.txt');
        foreach ([
            'lib/CompilerVersion.php',
            'lib/VM/SapiOutput.php',
            'lib/VM/ValueEchoSupport.php',
            'lib/VM/VmStringCompare.php',
        ] as $path) {
            $this->assertContains($path, $requires, 'core hub must include '.$path);
        }
    }

    public function testStandardRequiresCoverDsExtStandardStubs(): void
    {
        $root = dirname(__DIR__, 2);
        $requires = $this->readRequires($root.'/script/spine-chunk-standard-requires.txt');
        foreach ([
            'ext/standard/strncasecmp.php',
            'ext/standard/VmMath.php',
            'ext/standard/VmIni.php',
            'ext/standard/IncludePathResolveJitHelper.php',
        ] as $path) {
            $this->assertContains($path, $requires, 'standard hub must include '.$path);
        }
        $standardBind = (string) file_get_contents($root.'/lib/JIT/SpineChunkStandardHelperBind.php');
        $this->assertStringContainsString(
            "'includepathresolvejithelper' => '/ext/standard/IncludePathResolveJitHelper.php'",
            $standardBind
        );
    }

    /**
     * @return list<string>
     */
    private function readRequires(string $file): array
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $paths = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }
            $paths[] = $line;
        }

        return $paths;
    }
}
