<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Intersection TypeError for anonymous classes must strip NUL+filepath (#29569 / re-#26031).
 */
final class AnonIntersectionTypeErrorDisplayTest extends TestCase
{
    /**
     * @covers issue #29569
     */
    public function testAnonymousIntersectionTypeErrorUsesZendDisplayName(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
error_reporting(E_ALL);
interface A {}
interface B {}
function f(A&B $x): void {}
try {
    f(new class implements A {});
    echo "UNEXPECTED_OK\n";
} catch (\Throwable $e) {
    $m = $e->getMessage();
    echo str_contains($m, "\0") ? "HAS_NUL\n" : "NO_NUL\n";
    echo $m, "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'anon_intersection_typeerror.php'));
        $out = (string) ob_get_clean();
        $this->assertStringStartsWith("NO_NUL\n", $out);
        $this->assertStringContainsString('A@anonymous given', $out);
        $this->assertStringNotContainsString("\0", $out);
        // Must not embed internal provenance after @anonymous (file:line$id).
        $this->assertDoesNotMatchRegularExpression('/A@anonymous\0/', $out);
        $this->assertDoesNotMatchRegularExpression('/A@anonymous\/|A@anonymous\$/', $out);
    }

    /**
     * @covers issue #29569
     */
    public function testNamedIntersectionTypeErrorUnchanged(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
class OnlyA implements A {}
function f(A&B $x): void {}
try {
    f(new OnlyA());
    echo "UNEXPECTED_OK\n";
} catch (\Throwable $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'named_intersection_typeerror.php'));
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('OnlyA given', $out);
        $this->assertStringNotContainsString("\0", $out);
    }
}
