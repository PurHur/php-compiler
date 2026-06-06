<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #5473: class/interface constants compile when PHPCfg omits Const_->flags. */
final class ClassConstVisibilityFlagsTest extends TestCase
{
    public function testPublicClassAndInterfaceConstantsCompileWithoutCfgFlags(): void
    {
        $this->assertVmOutput(
            <<<'PHP'
<?php
class C {
    public const X = 1;
}
echo C::X, "\n";
interface I {
    public const Y = 2;
}
echo I::Y, "\n";
PHP,
            "1\n2\n"
        );
    }

    public function testPrivateClassConstantRejectedFromGlobalScope(): void
    {
        $this->assertVmOutput(
            <<<'PHP'
<?php
class C {
    private const X = 1;
}
try {
    echo C::X;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
PHP,
            "Cannot access private constant C::X\n"
        );
    }

    private function assertVmOutput(string $code, string $expected): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'test.php');
        ob_start();
        try {
            $runtime->run($block);
        } catch (VM\ScriptExit $e) {
            // exit() in compiled code
        }
        $actual = ob_get_clean();
        $this->assertSame($expected, $actual, 'VM stdout');
    }
}
