<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #5124 */
final class DelayedAttributeTargetTest extends TestCase
{
    public function testRejectsMethodOnlyOnPromotedParameter(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[Attribute(Attribute::TARGET_METHOD)]
class MethodOnly {}

class C {
    public function __construct(
        #[MethodOnly]
        public readonly string $x,
    ) {}
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Attribute "MethodOnly" cannot target property (allowed targets: method)'
        );
        $runtime->parseAndCompile($code, 'delayed_attr_promoted_invalid.php');
    }

    public function testAllowsPropertyOnlyOnPromotedParameter(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[Attribute(Attribute::TARGET_PROPERTY)]
class PropOnly {}

class C {
    public function __construct(
        #[PropOnly]
        public readonly string $x,
    ) {}
}
echo (new C('ok'))->x, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'delayed_attr_promoted_valid.php'));
        $this->assertSame("ok\n", ob_get_clean());
    }
}
