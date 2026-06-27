<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\Web\LiteralIncludeDiscovery;
use PHPCompiler\Test\Support\PropertyHookTestSkip;
use PHPUnit\Framework\TestCase;

/** @covers issue #6654 — SSOT preprocess chain for VM + AOT-minimal parse paths */
final class RuntimePreprocessTest extends TestCase
{
    use PropertyHookTestSkip;

    protected function setUp(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
    }

    private const BLOCK_HOOK_SRC = <<<'PHP'
<?php
class C {
    public int $x {
        get {
            return 42;
        }
    }
}
PHP;

    public function testPrepareSourceForParserLowersBlockPropertyHooks(): void
    {
        $runtime = new Runtime();
        [$out] = $runtime->prepareSourceForParser(self::BLOCK_HOOK_SRC, 'block_hook.php');
        self::assertStringNotContainsString('$x {', $out);
        self::assertStringContainsString('function __phpc_property_get_x', $out);
    }

    public function testRuntimeParseAcceptsBlockPropertyHooks(): void
    {
        $runtime = new Runtime();
        $script = $runtime->parse(self::BLOCK_HOOK_SRC, 'block_hook.php');
        self::assertNotEmpty($script->functions);
    }

    public function testLiteralIncludeDiscoveryUsesPrepareSourceForParser(): void
    {
        $path = sys_get_temp_dir().'/runtime_preprocess_'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($path, self::BLOCK_HOOK_SRC);
        try {
            $runtime = new Runtime(Runtime::MODE_AOT);
            $includes = LiteralIncludeDiscovery::discoverDirectAbsolutePaths($runtime, $path);
            self::assertSame([], $includes);
        } finally {
            @unlink($path);
        }
    }
}
