<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #11093 — consecutive fwrite/rewind/fscanf must not scramble call-arg slots. */
final class FscanfConsecutiveCallOpcodeTest extends TestCase
{
    public function testFscanfAfterRewindUsesStreamSlotNotPriorIntReturn(): void
    {
        $code = <<<'PHP'
<?php

$f = tmpfile();
$n = fwrite($f, '42 answer');
rewind($f);
$r = fscanf($f, '%d %s');
var_dump($n, $r);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'fscanf_consecutive_calls.php');

        $fwriteStreamSend = null;
        $fscanfStreamSend = null;
        $fcallOrdinal = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$fcallOrdinal;
            }
            if (2 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type && null === $fwriteStreamSend) {
                $fwriteStreamSend = $op->arg1;
            }
            if (4 === $fcallOrdinal && OpCode::TYPE_ARG_SEND === $op->type && null === $fscanfStreamSend) {
                $fscanfStreamSend = $op->arg1;
            }
        }

        self::assertNotNull($fwriteStreamSend, 'fwrite stream send missing');
        self::assertNotNull($fscanfStreamSend, 'fscanf stream send missing');
        self::assertSame(
            $fwriteStreamSend,
            $fscanfStreamSend,
            'fscanf must receive the same stream slot as fwrite'
        );

        ob_start();
        try {
            $runtime->run($block);
        } catch (\Throwable $e) {
            ob_end_clean();
            self::fail('runtime: '.$e->getMessage());
        }
        $out = ob_get_clean();
        self::assertStringContainsString('int(9)', $out);
        self::assertStringContainsString('42', $out);
    }

    public function testVarDumpFscanfNestedDoesNotScrambleStreamArg(): void
    {
        $code = <<<'PHP'
<?php

$f = tmpfile();
fwrite($f, '42 answer');
rewind($f);
var_dump(fscanf($f, '%d %s'));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'var_dump_fscanf_nested.php');

        ob_start();
        try {
            $runtime->run($block);
        } catch (\Throwable $e) {
            ob_end_clean();
            self::fail('runtime: '.$e->getMessage());
        }
        $out = ob_get_clean();
        self::assertStringContainsString('42', $out);
        self::assertStringContainsString('answer', $out);
    }
}
