<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for multi-type catch (issue #1362). */
final class MultiCatchTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        yield 'multi_catch.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/language/multi_catch.phpt',
            'multi_catch.phpt'
        );
    }

    public function testCatchTypeListLowering(): void
    {
        $code = <<<'PHP'
<?php
class E1 {}
class E2 {}
try {
    throw new E1();
} catch (E1|E2 $e) {
    echo "ok";
}
PHP;
        $runtime = new Runtime(Runtime::MODE_NORMAL);
        $block = $runtime->parseAndCompile($code, 'multi_catch_unit.php');
        $this->assertNotNull($block);
        $catch = $this->findFirstOpcode($block, OpCode::TYPE_CATCH);
        $this->assertNotNull($catch);
        $this->assertSame('e1|e2', $catch->catchTypes);
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
