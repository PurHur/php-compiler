<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Constructor property promotion (issue #1359). */
final class CtorPromotionTest extends TestCase
{
    public function testPromotedPropertyDefaultAndArgument(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public function __construct(private string $x = 'a') {}
    public function get(): string {
        return $this->x;
    }
}
echo (new C())->get(), "\n";
echo (new C('b'))->get(), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'ctor_promotion.php'));
        $out = ob_get_clean();
        $this->assertSame("a\nb\n", $out);
    }

    public function testProtectedPromotedProperty(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public function __construct(protected int $n) {}
    public function get(): int {
        return $this->n;
    }
}
echo (new C(7))->get();
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'ctor_promotion_protected.php'));
        $out = ob_get_clean();
        $this->assertSame('7', $out);
    }
}
