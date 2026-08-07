<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\CatchIntersectionSupport;
use PHPCompiler\Compiler\CompileFatal;

require_once __DIR__.'/../BaseTest.php';

/**
 * Catch intersection rejected like Zend (#28439; #28205 was inverted).
 */
final class CatchIntersectionTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        yield 'catch_intersection.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/language/catch_intersection.phpt',
            'catch_intersection.phpt'
        );
    }

    public function testCatchIntersectionRejectedAsParseError(): void
    {
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
class E extends Exception implements A, B {}
try { throw new E("x"); }
catch (A&B $e) { echo "caught"; }
PHP;
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(CatchIntersectionSupport::REFERENCE_PROFILE_UNEXPECTED_AMPERSAND);
        (new Runtime(Runtime::MODE_NORMAL))->parseAndCompile($code, 'catch_intersection_reject.php');
    }

    public function testParenthesizedCatchIntersectionRejected(): void
    {
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
class E extends Exception implements A, B {}
try { throw new E("x"); }
catch ((A&B) $e) { echo "caught"; }
PHP;
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(CatchIntersectionSupport::REFERENCE_PROFILE_UNEXPECTED_PAREN);
        (new Runtime(Runtime::MODE_NORMAL))->parseAndCompile($code, 'catch_paren_reject.php');
    }

    public function testUnionCatchStillWorks(): void
    {
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
class E extends Exception implements A, B {}
try { throw new E("x"); }
catch (A|B $e) { echo "caught"; }
PHP;
        $runtime = new Runtime(Runtime::MODE_NORMAL);
        $block = $runtime->parseAndCompile($code, 'catch_union_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('caught', ob_get_clean());
    }

    public function testParamIntersectionUnchanged(): void
    {
        $code = <<<'PHP'
<?php
interface I1 {}
interface I2 {}
class C implements I1, I2 {}
function f(I1&I2 $x): int { return 1; }
echo f(new C());
PHP;
        $runtime = new Runtime(Runtime::MODE_NORMAL);
        $block = $runtime->parseAndCompile($code, 'param_intersection_unit.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        $this->assertSame('1', $out);
    }
}
