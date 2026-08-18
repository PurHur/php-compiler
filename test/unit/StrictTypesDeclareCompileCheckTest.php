<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #32182 — mid-file declare(strict_types) is a Zend compile-time fatal */
final class StrictTypesDeclareCompileCheckTest extends TestCase
{
    /**
     * @dataProvider illegalStrictTypesProvider
     */
    public function testLateStrictTypesFailsAtCompileTime(string $code): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'strict_types declaration must be the very first statement in the script'
        );
        $runtime->parseAndCompile($code, 'strict_types_midfile.php');
    }

    /** @return iterable<string, array{string}> */
    public static function illegalStrictTypesProvider(): iterable
    {
        yield 'after echo' => ["<?php echo 'a'; declare(strict_types=1); echo 'b';"];
        yield 'after echo strict_types=0' => ["<?php echo 'a'; declare(strict_types=0); echo 'b';"];
        yield 'inside function' => ["<?php function foo() { declare(strict_types=1); } echo \"accepted\\n\";"];
        yield 'inside method' => [
            '<?php class C { public function m() { declare(strict_types=1); } } echo "accepted\n";',
        ];
        yield 'after namespace' => ["<?php namespace Foo; declare(strict_types=1); echo 'accepted';"];
        yield 'inside ticks block' => ["<?php declare(ticks=1) { declare(strict_types=1); } echo 'accepted';"];
    }

    public function testBlockModeIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('strict_types declaration must not use block mode');
        $runtime->parseAndCompile(
            "<?php declare(strict_types=1) { echo 'x'; }",
            'strict_types_block.php'
        );
    }

    public function testLegalFirstStatementStillEnablesStrictTypes(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
            <?php
            declare(strict_types=1);
            function f(int $x): int { return $x; }
            try {
                echo f("1");
            } catch (\TypeError $e) {
                echo "TypeError";
            }
            PHP,
            'strict_types_ok.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('TypeError', ob_get_clean());
    }

    public function testLeadingTicksThenStrictTypesIsLegal(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            "<?php declare(ticks=1); declare(strict_types=1); echo 'ok';",
            'strict_types_after_ticks.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('ok', ob_get_clean());
    }

    public function testSameLineOpenTagDeclareStillEnablesStrictTypes(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
            <?php declare(strict_types=1);
            function f(int $x): int { return $x; }
            try {
                echo f("1");
            } catch (\TypeError $e) {
                echo "TypeError";
            }
            PHP,
            'strict_types_same_line.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('TypeError', ob_get_clean());
    }
}
