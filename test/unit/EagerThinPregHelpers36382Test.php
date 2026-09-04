<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\Web\SourceBundler;
use PHPUnit\Framework\TestCase;

/**
 * Large IncludeHelper graphs must NestedJIT thin preg helpers before user IR fattens the module (#36382).
 */
final class EagerThinPregHelpers36382Test extends TestCase
{
    public function testRuntimeFlagDefaultsOff(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $this->assertFalse($runtime->eagerThinPregHelpers);
    }

    public function testIncrementalRequiresThresholdMatchesSlimGraph(): void
    {
        $this->assertSame(32, SourceBundler::INCREMENTAL_REQUIRES_UNIT_THRESHOLD);
        $paths = [];
        for ($i = 0; $i < 32; ++$i) {
            $paths[] = '/tmp/unit_'.$i.'.php';
        }
        $this->assertTrue(SourceBundler::shouldUseIncrementalRequires($paths));
        $this->assertFalse(SourceBundler::shouldUseIncrementalRequires(array_slice($paths, 0, 31)));
    }

    public function testCompilePhpSetsEagerFlagSourceGuard(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/bin/compile.php');
        $this->assertStringContainsString('$eagerThinPregHelpers = true', $src);
        $this->assertStringContainsString('eagerThinPregHelpers = true', $src);
        $this->assertStringContainsString('eager thin preg NestedJIT', $src);

        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('maybeEagerLinkThinPregHelpers', $jit);
        $this->assertStringContainsString('eager_thin_preg_begin', $jit);
        $this->assertStringContainsString('PregMatchRuntime::ensureLinked', $jit);

        $runtime = (string) file_get_contents(dirname(__DIR__, 2).'/lib/Runtime.php');
        $this->assertStringContainsString('eager_thin_preg_begin', $runtime);
        $this->assertStringContainsString('eagerThinPregHelpers', $runtime);
    }
}
