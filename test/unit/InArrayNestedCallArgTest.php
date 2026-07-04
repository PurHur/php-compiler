<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #16013 — in_array() as nested call argument must materialize bool. */
final class InArrayNestedCallArgTest extends TestCase
{
    public function testNestedInArrayCallArgReturnsBool(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
function probe(string $label, mixed $result): void
{
    echo $label . ': ' . (is_bool($result) ? ($result ? 'true' : 'false') : json_encode($result)) . "\n";
}
probe('nested', in_array(1, ['T' => 1], true));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'in_array_nested_call_arg.php');

        $inArrayReturnSlot = null;
        $probeSends = [];
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
                if (2 === $fcallOrdinal) {
                    $probeSends = [];
                }
            }
            if (1 === $fcallOrdinal && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $inArrayReturnSlot = $op->arg1;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type) {
                $probeSends[] = $op->arg1;
            }
        }

        self::assertNotNull($inArrayReturnSlot, 'expected in_array EXEC_RETURN slot');
        self::assertCount(2, $probeSends, 'probe arg sends=' . json_encode($probeSends));
        self::assertSame($inArrayReturnSlot, $probeSends[1], 'probe arg #1 must use in_array return slot');

        ob_start();
        $runtime->run($block);
        self::assertSame("nested: true\n", ob_get_clean());
    }
}
