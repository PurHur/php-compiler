<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Issue #34818 — true ? strlen/crc32/userFn : "bad" must match Zend under AOT.
 * getOperand must prefer Temporary phi over FUNCCALL name Literal at the same slot.
 */
final class Issue34818TernaryIntUserPhiAotTest extends TestCase
{
    public function testIfArmAssignDestIsPhiTemporaryNotFuncNameLiteral(): void
    {
        $runtime = new Runtime();
        $compiled = $runtime->compile(
            $runtime->parse(
                '<?php echo true ? strlen("abc") : "bad", "\n";',
                'issue_34818.php'
            )
        );
        $seen = new \SplObjectStorage();
        $stack = [$compiled];
        $foundCallArm = false;
        while ([] !== $stack) {
            $block = array_pop($stack);
            if ($seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_ASSIGN !== $op->type || null === $op->arg3) {
                    continue;
                }
                $rhs = $block->getOperand((int) $op->arg3);
                if (!$rhs instanceof \PHPCfg\Operand\Temporary) {
                    continue;
                }
                // Call-arm RHS is Temporary int; dest (arg2) must be the phi Temporary,
                // not LITERAL('strlen').
                if (null === $rhs->type || false === strpos((string) $rhs->type, 'int')) {
                    continue;
                }
                $dest = $block->getOperand((int) $op->arg2);
                self::assertInstanceOf(
                    \PHPCfg\Operand\Temporary::class,
                    $dest,
                    '?: call-arm ASSIGN dest must be phi Temporary (#34818)'
                );
                self::assertNotInstanceOf(
                    \PHPCfg\Operand\Literal::class,
                    $dest
                );
                $foundCallArm = true;
            }
            foreach ($block->opCodes as $op) {
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof Block) {
                        $stack[] = $sub;
                    }
                }
            }
        }
        self::assertTrue($foundCallArm, 'expected strlen ?: call-arm ASSIGN');
    }

    public function testVmMatchesZendOnTrueTernaryIntAndUserFn(): void
    {
        $path = __DIR__ . '/../repro/issue_34818_ternary_int_user_phi_aot.php';
        $code = file_get_contents($path);
        self::assertIsString($code);
        $runtime = new Runtime();
        $compiled = $runtime->compile($runtime->parse($code, 'issue_34818.php'));
        ob_start();
        $runtime->run($compiled);
        $out = ob_get_clean();
        self::assertSame("3\n65\nAB\n", $out);
    }
}
