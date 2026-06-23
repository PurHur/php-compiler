<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class IteratorToArrayClosureReturnSlotTest extends TestCase
{
    public function testClosureReturnIteratorToArrayUsesExecReturn(): void
    {
        $code = <<<'PHP'
<?php
return (function () {
    return iterator_to_array((function () {
        yield 1;
    })());
})();
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'ita_closure.php');

        $execReturns = [];
        $walk = static function (Block $b) use (&$walk, &$execReturns): void {
            foreach ($b->opCodes as $op) {
                if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                    $execReturns[] = (int) $op->arg1;
                }
                if (OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type) {
                    $execReturns[] = -1;
                }
                if (OpCode::TYPE_CLOSURE === $op->type && $op->block1 instanceof Block) {
                    $walk($op->block1);
                }
            }
        };
        $walk($block);

        self::assertContains(6, $execReturns, 'iterator_to_array return slot: '.json_encode($execReturns));
        self::assertNotContains(-1, $execReturns, 'no FUNCCALL_EXEC_NORETURN in closure chain');
    }

    public function testClosureReturnIteratorToArrayRuntime(): void
    {
        $code = <<<'PHP'
<?php
echo var_export((function () {
    return iterator_to_array((function () {
        yield 1;
        yield 2;
    })());
})(), true);
PHP;
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'ita_closure.php'));
        $out = ob_get_clean();
        self::assertSame('array (
  0 => 1,
  1 => 2,
)', $out);
    }
}
