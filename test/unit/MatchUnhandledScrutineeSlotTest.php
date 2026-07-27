<?php

declare(strict_types=1);

namespace PHPCompiler;

/** Guard match scrutinee stays live as ARG_SEND into UnhandledMatchError message helper (#13955, #23664). */
final class MatchUnhandledScrutineeSlotTest extends \PHPUnit\Framework\TestCase
{
    public function testScopeSlotReadForUnhandledMatchMessageHelper(): void
    {
        $rt = new Runtime();
        $block = $rt->parseAndCompile(
            '<?php enum E { case A; } try { match (E::A) { 1 => 0 }; } catch (UnhandledMatchError $e) { echo $e->getMessage(); }',
            'match_unhandled_scrutinee_slot.php'
        );
        self::assertNotNull($block);

        $found = false;
        $stack = [$block];
        $seen = [];
        while ($stack !== []) {
            $b = \array_pop($stack);
            $id = \spl_object_id($b);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($b->opCodes as $i => $op) {
                if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                    $name = $b->constants[$op->arg1]->toString();
                    if ('phpc_match_unhandled_operand_message' === $name) {
                        self::assertSame(
                            OpCode::TYPE_ARG_SEND,
                            $b->opCodes[$i + 1]->type ?? null,
                            'message helper must receive scrutinee via ARG_SEND'
                        );
                        $found = true;
                    }
                }
                if ($op->block1 instanceof Block) {
                    $stack[] = $op->block1;
                }
                if ($op->block2 instanceof Block) {
                    $stack[] = $op->block2;
                }
            }
        }
        self::assertTrue($found, 'expected phpc_match_unhandled_operand_message FUNCCALL_INIT');
    }
}
