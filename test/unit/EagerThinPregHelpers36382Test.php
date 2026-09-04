<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\Web\SourceBundler;
use PHPUnit\Framework\TestCase;

/**
 * Large IncludeHelper graphs prefer deferred thin-preg NestedJIT (#36382).
 *
 * Nyholm Uri uses UriRawurlencodeReplaceJitHelper; eager PregAotFastPath NestedJIT fattened
 * the module and stalled Uri method lowering. Flag remains available for opt-in.
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

    public function testCompilePhpDoesNotForceEagerThinPregForIncrementalGraphs(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/bin/compile.php');
        $this->assertStringContainsString('$eagerThinPregHelpers = false', $src);
        $this->assertStringContainsString('UriRawurlencodeReplaceJitHelper', $src);
        // Incremental path must not flip the flag on (Uri specialization #36382).
        $this->assertDoesNotMatchRegularExpression(
            '/shouldUseIncrementalRequires\(\$includes\)\s*\{[^}]*\$eagerThinPregHelpers\s*=\s*true/s',
            $src
        );

        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('maybeEagerLinkThinPregHelpers', $jit);
        $this->assertStringContainsString('eager_thin_preg_begin', $jit);

        $callback = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/JitPregReplaceCallback.php');
        $this->assertStringContainsString('UriRawurlencodeReplaceJitHelper', $callback);
        $this->assertStringContainsString('uri_rawurlencode_replace_fast', $callback);
    }
}
