<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\EnumCaseListRewriter;
use PHPUnit\Framework\TestCase;

final class EnumCaseListRewriterTest extends TestCase
{
    public function testExpandsUnitEnumCaseList(): void
    {
        if (!CompilerVersion::supportsEnumCaseList()) {
            $this->markTestSkipped('enum case list syntax disabled on reference profile');
        }
        $src = <<<'PHP'
<?php
enum E {
    case A, B, C;
}
PHP;
        $out = EnumCaseListRewriter::rewrite($src);
        $this->assertStringContainsString('case A;', $out);
        $this->assertStringContainsString('case B;', $out);
        $this->assertStringContainsString('case C;', $out);
        $this->assertStringNotContainsString('case A, B', $out);
    }

    public function testExpandsBackedEnumCaseList(): void
    {
        if (!CompilerVersion::supportsEnumCaseList()) {
            $this->markTestSkipped('enum case list syntax disabled on reference profile');
        }
        $src = <<<'PHP'
<?php
enum Color: string {
    case Red = 'r', Blue = 'b';
}
PHP;
        $out = EnumCaseListRewriter::rewrite($src);
        $this->assertStringContainsString("case Red = 'r';", $out);
        $this->assertStringContainsString("case Blue = 'b';", $out);
    }

    public function testLeavesSwitchCommaCaseUntouched(): void
    {
        $src = <<<'PHP'
<?php
switch ($x) {
    case 1, 2:
        break;
}
PHP;
        $this->assertSame($src, EnumCaseListRewriter::rewrite($src));
    }

    public function testLeavesMethodSwitchInsideEnumUntouched(): void
    {
        $src = <<<'PHP'
<?php
enum E {
    case A;
    public function f(int $x): void {
        switch ($x) {
            case 1, 2:
                break;
        }
    }
}
PHP;
        $this->assertSame($src, EnumCaseListRewriter::rewrite($src));
    }
}
