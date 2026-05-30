<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPCompiler\SwitchCommaCaseRewriter;

final class SwitchCommaCaseRewriterTest extends TestCase
{
    public function testExpandsCommaSeparatedCaseLabels(): void
    {
        $source = <<<'PHP'
<?php
switch (2) {
    case 1, 2:
        echo "hit\n";
        break;
}
PHP;
        $rewritten = SwitchCommaCaseRewriter::rewrite($source);
        self::assertStringContainsString('case 1:', $rewritten);
        self::assertStringContainsString('case 2:', $rewritten);
        self::assertStringNotContainsString('case 1, 2:', $rewritten);
    }

    public function testLeavesSingleLabelCaseUnchanged(): void
    {
        $source = "case 1:\n";
        self::assertSame($source, SwitchCommaCaseRewriter::rewrite($source));
    }

    public function testCommaInsideParensIsNotASeparator(): void
    {
        $source = 'case foo(1, 2):';
        self::assertSame($source, SwitchCommaCaseRewriter::rewrite($source));
    }
}
