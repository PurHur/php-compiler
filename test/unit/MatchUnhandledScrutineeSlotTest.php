<?php

declare(strict_types=1);

namespace PHPCompiler;

/** Guard match scrutinee slot survives fcall release when JUMPIF targets re-read it (#13955). */
final class MatchUnhandledScrutineeSlotTest extends \PHPUnit\Framework\TestCase
{
    public function testScopeSlotReadInJumpTargetsDetectsUnhandledMatchGetClassArm(): void
    {
        $rt = new Runtime();
        $block = $rt->parseAndCompileFile(
            __DIR__ . '/../repro/maintainer_gap_match_unhandled_enum.php'
        );
        self::assertNotNull($block);

        $probeBlock = $this->findMatchUnhandledProbeBlock($block->opCodes[1]->block1);
        self::assertNotNull($probeBlock, 'expected phpc_match_unhandled_operand_is_object block');

        $scrutineeSlot = (int) $probeBlock->opCodes[1]->arg1;
        self::assertTrue(
            $probeBlock->scopeSlotReadInJumpTargets($scrutineeSlot),
            'scrutinee slot must be treated live for JUMPIF unhandled arms'
        );
    }

    /**
     * @param list<OpCode> $opCodes
     */
    private function findMatchUnhandledProbeBlock(Block $root): ?Block
    {
        foreach ($root->opCodes as $op) {
            if (OpCode::TYPE_JUMPIF !== $op->type) {
                continue;
            }
            foreach ([$op->block2, $op->block1] as $candidate) {
                if (
                    $candidate instanceof Block
                    && 4 === \count($candidate->opCodes)
                    && OpCode::TYPE_ARG_SEND === $candidate->opCodes[1]->type
                ) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
