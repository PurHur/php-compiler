<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #15930 — array_keys([null => 1], null) inline haystack arg wiring. */
final class ArrayKeysNullInlineLiteralTest extends TestCase
{
    public function testInlineNullKeyHaystackWiresArrayProducerToArgZero(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
echo var_export(array_keys([null => 1], null), true);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_keys_null_inline.php');

        $initArraySlot = null;
        $nullSearchSlot = null;
        $argSends = [];
        $constFetchOrdinals = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CONST_FETCH === $op->type) {
                $constFetchOrdinals[] = $op->arg1;
            }
            if (OpCode::TYPE_INIT_ARRAY === $op->type) {
                $initArraySlot = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (1 === $fcallOrdinal) {
                    $argSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $argSends[] = $op->arg1;
            }
        }
        if (\count($constFetchOrdinals) >= 2) {
            $nullSearchSlot = $constFetchOrdinals[1];
        }

        self::assertNotNull($initArraySlot, 'expected INIT_ARRAY slot');
        self::assertCount(2, $argSends, 'arg sends=' . json_encode($argSends));
        self::assertSame($initArraySlot, $argSends[0], 'arg sends=' . json_encode($argSends) . ' init=' . $initArraySlot);
        self::assertSame($nullSearchSlot, $argSends[1], 'search must use trailing null ConstFetch slot');

        ob_start();
        $runtime->run($block);
        self::assertSame('array (
)', ob_get_clean());
    }
}
