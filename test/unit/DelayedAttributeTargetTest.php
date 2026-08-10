<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #5124 / #25729 — user TARGET_* deferred to ReflectionAttribute::newInstance */
final class DelayedAttributeTargetTest extends TestCase
{
    public function testMethodOnlyOnPromotedParameterDefersToNewInstance(): void
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
        $runtime->run($runtime->parseAndCompile($code, 'delayed_attr_promoted_deferred.php'));
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

    /** @covers issue #29918 — Attribute(0) empty allowed-targets list matches Zend */
    public function testZeroTargetMaskErrorListsEmptyAllowedTargets(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[Attribute(0)]
class A {}
#[A]
class C {}
try {
    (new ReflectionClass(C::class))->getAttributes()[0]->newInstance();
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attr_zero_targets_29918.php'));
        $this->assertSame(
            "Error: Attribute \"A\" cannot target class (allowed targets: )\n",
            ob_get_clean()
        );
    }
}
