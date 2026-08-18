<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #32228 — file-scope const true/false/null are Zend compile-time fatals */
final class ReservedGlobalConstCompileFatalTest extends TestCase
{
    /**
     * @dataProvider illegalReservedGlobalConstProvider
     */
    public function testReservedGlobalConstFailsAtCompileTime(string $code, string $spelling): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage("Cannot redeclare constant '{$spelling}'");
        $runtime->parseAndCompile($code, 'reserved_global_const.php');
    }

    /** @return iterable<string, array{string, string}> */
    public static function illegalReservedGlobalConstProvider(): iterable
    {
        yield 'const true' => ['<?php const true = 1; echo "accepted\n";', 'true'];
        yield 'const false' => ['<?php const false = 1; echo "accepted\n";', 'false'];
        yield 'const null' => ['<?php const null = 1; echo "accepted\n";', 'null'];
        yield 'const TRUE' => ['<?php const TRUE = 1; echo "accepted\n";', 'TRUE'];
        yield 'const True' => ['<?php const True = 1; echo "accepted\n";', 'True'];
        yield 'const False' => ['<?php const False = 1; echo "accepted\n";', 'False'];
        yield 'const NULL' => ['<?php const NULL = 1; echo "accepted\n";', 'NULL'];
        yield 'namespaced const true' => ['<?php namespace Foo; const true = 1; echo "accepted\n";', 'true'];
        yield 'namespaced const TRUE' => ['<?php namespace Foo; const TRUE = 1; echo "accepted\n";', 'TRUE'];
    }

    public function testLegalGlobalConstStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php const TRUTH = 1; echo TRUTH;',
            'reserved_global_const_ok.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('1', ob_get_clean());
    }

    public function testDefineTrueWarnsRatherThanCompileFatal(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            "<?php define('true', 1); echo \"ran\\n\";",
            'define_true_warns.php'
        );
        $this->assertNotNull($block, 'define(true) must compile, not compile-fatal');
        ob_start();
        $runtime->run($block);
        $this->assertSame("ran\n", ob_get_clean());
    }

    public function testClassConstTrueStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php class C { const true = 1; } echo C::true;',
            'class_const_true_ok.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('1', ob_get_clean());
    }
}
