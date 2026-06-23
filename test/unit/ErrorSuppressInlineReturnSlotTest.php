<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class ErrorSuppressInlineReturnSlotTest extends TestCase
{
    public function testInlineAtStatUsesReturnSlotForSuppressInnerCall(): void
    {
        $code = <<<'PHP'
<?php
var_export(@stat('/no'));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'inline_at_stat.php');

        $suppressStatReturnSlot = null;
        $varExportSendSlot = null;
        $suppressHasNoreturn = false;
        $endStatInits = 0;

        $walk = static function (Block $b) use (&$walk, &$suppressStatReturnSlot, &$varExportSendSlot, &$suppressHasNoreturn, &$endStatInits): void {
            $inSuppress = null !== $b->orig && $b->orig instanceof \PHPCfg\ErrorSuppressBlock;
            $inEnd = null !== $b->orig && 1 === \count($b->orig->parents) && $b->orig->parents[0] instanceof \PHPCfg\ErrorSuppressBlock;
            $pendingInit = false;
            foreach ($b->opCodes as $op) {
                if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                    if ($inEnd) {
                        ++$endStatInits;
                    }
                    $pendingInit = true;
                    continue;
                }
                if ($pendingInit && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                    if ($inSuppress) {
                        $suppressStatReturnSlot = $op->arg1;
                    }
                    $pendingInit = false;
                    continue;
                }
                if ($pendingInit && OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type) {
                    if ($inSuppress) {
                        $suppressHasNoreturn = true;
                    }
                    $pendingInit = false;
                    continue;
                }
                if (OpCode::TYPE_ARG_SEND === $op->type && $inEnd && null === $varExportSendSlot) {
                    $varExportSendSlot = $op->arg1;
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof Block) {
                        $walk($sub);
                    }
                }
            }
        };
        $walk($block);

        self::assertSame(1, $endStatInits, 'end block should not re-emit suppressed inner call');
        self::assertFalse($suppressHasNoreturn, 'suppress inner stat must use EXEC_RETURN');
        self::assertNotNull($suppressStatReturnSlot, 'suppress stat return slot');
        self::assertSame($suppressStatReturnSlot, $varExportSendSlot, 'var_export arg must read suppress result slot');
    }
}
