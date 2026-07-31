<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Promoted-parameter attribute target remap (#5124 timing) + Zend deferral (#25729).
 *
 * Wrong user TARGET_* on a promoted property is not a compile fatal; newInstance() throws.
 */
final class DelayedAttributeTargetTest extends TestCase
{
    public function testMethodOnlyOnPromotedParameterDeferredToNewInstance(): void
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
$attrs = (new ReflectionProperty(C::class, 'x'))->getAttributes();
echo count($attrs), "\n";
try {
    $attrs[0]->newInstance();
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'delayed_attr_promoted_invalid.php'));
        $this->assertSame(
            "1\nError: Attribute \"MethodOnly\" cannot target property (allowed targets: method)\n",
            ob_get_clean()
        );
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
