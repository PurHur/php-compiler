<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * bootstrapScanConstructs yield detection (issue #2483).
 */
final class BootstrapConstructScanTest extends TestCase
{
    private static function loadBootstrapLib(): void
    {
        if (!function_exists('bootstrapScanConstructs')) {
            require_once dirname(__DIR__, 2).'/script/bootstrap-lib.php';
        }
    }

    public function testYieldInsideNamedFunctionIsNotBlocker(): void
    {
        self::loadBootstrapLib();

        $code = <<<'PHP'
<?php
function gen(): Generator {
    yield 1;
}
PHP;
        $result = bootstrapScanConstructsToken($code);
        $this->assertSame([], $result['blockers']);
    }

    public function testYieldInsideMethodIsNotBlocker(): void
    {
        self::loadBootstrapLib();

        $code = <<<'PHP'
<?php
class Block {
    public function each(): Generator {
        yield [1, 2];
    }
}
PHP;
        $result = bootstrapScanConstructsToken($code);
        $this->assertSame([], $result['blockers']);
    }

    public function testScriptScopeYieldIsBlocker(): void
    {
        self::loadBootstrapLib();

        $code = <<<'PHP'
<?php
yield 1;
PHP;
        $result = bootstrapScanConstructsToken($code);
        $this->assertCount(1, $result['blockers']);
        $this->assertStringContainsString('generator yield (script scope', $result['blockers'][0]);
    }

    public function testBlockPhpHasNoYieldBlockers(): void
    {
        self::loadBootstrapLib();

        $root = dirname(__DIR__, 2);
        $result = bootstrapScanConstructs($root.'/lib/Block.php');
        $this->assertSame([], $result['blockers'], implode(', ', $result['blockers']));
    }
}
