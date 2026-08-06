<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for catch intersection types (issue #28205). */
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

    public function testCatchIntersectionTypeListLowering(): void
    {
        $code = <<<'PHP'
<?php
class A extends Exception implements Countable {
    public function count(): int { return 0; }
}
try {
    throw new A();
} catch (Countable&Throwable $e) {
    echo "ok";
}
PHP;
        $runtime = new Runtime(Runtime::MODE_NORMAL);
        $block = $runtime->parseAndCompile($code, 'catch_intersection_unit.php');
        $this->assertNotNull($block);
        $catch = $this->findFirstOpcode($block, OpCode::TYPE_CATCH);
        $this->assertNotNull($catch);
        $this->assertSame('countable&throwable', $catch->catchTypes);
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

    private function findFirstOpcode(Block $block, int $opcode): ?OpCode
    {
        foreach ($block->opCodes as $op) {
            if ($opcode === $op->type) {
                return $op;
            }
            if (null !== $op->block1) {
                $found = $this->findFirstOpcode($op->block1, $opcode);
                if (null !== $found) {
                    return $found;
                }
            }
            if (null !== $op->block2) {
                $found = $this->findFirstOpcode($op->block2, $opcode);
                if (null !== $found) {
                    return $found;
                }
            }
        }

        return null;
    }
}
