<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class ArrayIntersectUkeyThreeArrayTest extends TestCase
{
    public function testThreeArrayIntersectUkeySendSlots(): void
    {
        $code = <<<'PHP'
<?php
var_export(array_intersect_ukey(['a' => 1, 'b' => 2, 'c' => 3], ['A' => 10], ['a' => 20], 'strcasecmp'));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'ukey.php');
        $sends = [];
        $inits = [];
        $buf = [];
        foreach ($block->opCodes as $i => $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                $buf = [];
                $inits[] = $i;
            }
            if (OpCode::TYPE_INIT_ARRAY === $op->type) {
                $inits[] = 'array:'.$op->arg1;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                $buf[] = $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && 4 === \count($buf)) {
                $sends = $buf;
            }
        }
        self::assertCount(4, $sends, json_encode($sends));
        self::assertNotNull($sends[0]);
        self::assertNotSame($sends[0], $sends[3]);

        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();
        self::assertSame("array (\n  'a' => 1,\n)", $output);
    }
}
