<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * If/elseif assigns to `$text` must share one CV; `$permitRawHtml = true` must not
 * clobber it (Parsedown::element rawHtml path, #36380).
 *
 * php-src: Zend/zend_compile.c CV allocation — one slot per local name per op_array.
 */
final class LocalSlotAliasRawHtml36380Test extends TestCase
{
    public function testParsedownElementShapeKeepsRawHtmlTextUnderVm(): void
    {
        $code = file_get_contents(__DIR__ . '/../repro/local_slot_alias_rawhtml_36380.php');
        self::assertIsString($code);
        $runtime = new Runtime(Runtime::MODE_NORMAL);
        $block = $runtime->parseAndCompile($code, 'local_slot_alias_rawhtml_36380.php');
        self::assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $out = (string) ob_get_clean();
        self::assertSame("OK\n", $out);
    }

    public function testTextCvUnifiedAcrossIfElseifArms(): void
    {
        $code = file_get_contents(__DIR__ . '/../repro/local_slot_alias_rawhtml_36380.php');
        self::assertIsString($code);
        $runtime = new Runtime(Runtime::MODE_NORMAL);
        $script = $runtime->parse($code, 'probe.php');
        $compiled = $runtime->compile($script);
        $slots = [];
        foreach ($this->allBlocks($compiled) as $block) {
            $slot = $block->slotIndexForVariableName('text');
            if (null !== $slot) {
                $slots[$slot] = true;
            }
        }
        self::assertCount(
            1,
            $slots,
            '`$text` must use one CV slot across if/elseif arms (#36380), got: '
            . implode(',', array_keys($slots))
        );
    }

    /** @return list<Block> */
    private function allBlocks(Block $root): array
    {
        $seen = new \SplObjectStorage();
        $stack = [$root];
        $out = [];
        while ($stack) {
            $b = array_pop($stack);
            if ($seen->contains($b)) {
                continue;
            }
            $seen->attach($b);
            $out[] = $b;
            foreach ($b->opCodes as $op) {
                if (isset($op->block1) && $op->block1 instanceof Block) {
                    $stack[] = $op->block1;
                }
                if (isset($op->block2) && $op->block2 instanceof Block) {
                    $stack[] = $op->block2;
                }
            }
            if (!empty($b->functions)) {
                foreach ($b->functions as $fn) {
                    if ($fn instanceof Block) {
                        $stack[] = $fn;
                    }
                }
            }
        }

        return $out;
    }
}
