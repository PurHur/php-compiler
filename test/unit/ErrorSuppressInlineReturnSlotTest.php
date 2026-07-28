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

    public function testNestedArgCallUnderSuppressUsesOuterReturnSlot(): void
    {
        $code = <<<'PHP'
<?php
$missing = '/no';
var_export(@copy($missing, sys_get_temp_dir() . '/dst.txt'));
var_export(@touch('/no/parent_' . getmypid() . '/f.txt'));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'suppress_nested_arg.php');

        $lastSuppressReturnByBlock = [];
        $endSendSlotByBlock = [];
        $walk = static function (Block $b) use (&$walk, &$lastSuppressReturnByBlock, &$endSendSlotByBlock): void {
            $blockId = spl_object_id($b);
            $inSuppress = null !== $b->orig && $b->orig instanceof \PHPCfg\ErrorSuppressBlock;
            $inEnd = null !== $b->orig && 1 === \count($b->orig->parents) && $b->orig->parents[0] instanceof \PHPCfg\ErrorSuppressBlock;
            $pendingInit = false;
            foreach ($b->opCodes as $op) {
                if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                    $pendingInit = true;
                    continue;
                }
                if ($pendingInit && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                    if ($inSuppress) {
                        $lastSuppressReturnByBlock[$blockId] = $op->arg1;
                    }
                    $pendingInit = false;
                    continue;
                }
                if ($pendingInit && OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type) {
                    $pendingInit = false;
                    continue;
                }
                if (OpCode::TYPE_ARG_SEND === $op->type && $inEnd) {
                    $endSendSlotByBlock[$blockId] = $op->arg1;
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof Block) {
                        $walk($sub);
                    }
                }
            }
        };
        $walk($block);

        self::assertCount(2, $lastSuppressReturnByBlock, 'copy and touch suppress regions');
        self::assertCount(2, $endSendSlotByBlock, 'var_export reads both suppress results');
        foreach ($lastSuppressReturnByBlock as $suppressSlot) {
            self::assertContains(
                $suppressSlot,
                array_values($endSendSlotByBlock),
                'var_export arg must read outer suppress call return slot'
            );
        }
    }

    public function testSuppressInnerCallArgWithTrailingTrueLiteralUsesSuppressReturnSlot(): void
    {
        $code = <<<'PHP'
<?php
echo var_export(@get_cfg_var('display_errors'), true);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'suppress_get_cfg_var_var_export.php');

        $suppressReturnSlot = null;
        $varExportFirstSendSlot = null;
        $varExportSecondSendSlot = null;
        $walk = static function (Block $b) use (&$walk, &$suppressReturnSlot, &$varExportFirstSendSlot, &$varExportSecondSendSlot): void {
            $inSuppress = null !== $b->orig && $b->orig instanceof \PHPCfg\ErrorSuppressBlock;
            $inEnd = null !== $b->orig && 1 === \count($b->orig->parents) && $b->orig->parents[0] instanceof \PHPCfg\ErrorSuppressBlock;
            $pendingInit = false;
            $varExportSendIndex = 0;
            foreach ($b->opCodes as $op) {
                if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                    $pendingInit = true;
                    if ($inEnd) {
                        $varExportSendIndex = 0;
                    }
                    continue;
                }
                if ($pendingInit && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                    if ($inSuppress) {
                        $suppressReturnSlot = $op->arg1;
                    }
                    $pendingInit = false;
                    continue;
                }
                if ($pendingInit && OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type) {
                    $pendingInit = false;
                    continue;
                }
                if (OpCode::TYPE_ARG_SEND === $op->type && $inEnd) {
                    if (0 === $varExportSendIndex) {
                        $varExportFirstSendSlot = $op->arg1;
                    } elseif (1 === $varExportSendIndex) {
                        $varExportSecondSendSlot = $op->arg1;
                    }
                    ++$varExportSendIndex;
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof Block) {
                        $walk($sub);
                    }
                }
            }
        };
        $walk($block);

        self::assertNotNull($suppressReturnSlot, 'suppress get_cfg_var return slot');
        self::assertSame($suppressReturnSlot, $varExportFirstSendSlot, 'var_export arg #0 must read suppress result');
        self::assertNotSame($varExportFirstSendSlot, $varExportSecondSendSlot, 'var_export arg #1 must not alias arg #0');
    }

    public function testStandaloneSuppressStatementDoesNotAliasHoistedNullCallArg(): void
    {
        $code = <<<'PHP'
<?php
@mkdir('/tmp/phpc_suppress_hoist_gap');
$ctx = stream_context_create(null);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'suppress_then_hoisted_null.php');

        $suppressReturnSlot = null;
        $streamContextSendSlot = null;
        $walk = static function (Block $b) use (&$walk, &$suppressReturnSlot, &$streamContextSendSlot): void {
            $inSuppress = null !== $b->orig && $b->orig instanceof \PHPCfg\ErrorSuppressBlock;
            $inEnd = null !== $b->orig && 1 === \count($b->orig->parents) && $b->orig->parents[0] instanceof \PHPCfg\ErrorSuppressBlock;
            $pendingInit = false;
            foreach ($b->opCodes as $op) {
                if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                    $pendingInit = true;
                    continue;
                }
                if ($pendingInit && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                    if ($inSuppress) {
                        $suppressReturnSlot = $op->arg1;
                    }
                    $pendingInit = false;
                    continue;
                }
                if ($pendingInit && OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type) {
                    $pendingInit = false;
                    continue;
                }
                if (OpCode::TYPE_ARG_SEND === $op->type && $inEnd && null === $streamContextSendSlot) {
                    $streamContextSendSlot = $op->arg1;
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof Block) {
                        $walk($sub);
                    }
                }
            }
        };
        $walk($block);

        self::assertNotNull($suppressReturnSlot, 'suppress mkdir return slot');
        self::assertNotNull($streamContextSendSlot, 'stream_context_create arg send slot');
        self::assertNotSame(
            $suppressReturnSlot,
            $streamContextSendSlot,
            'hoisted null must not alias unrelated @mkdir return slot'
        );
    }

    public function testStandaloneSuppressStatementDoesNotAliasHoistedEmptyArrayCallArg(): void
    {
        $code = <<<'PHP'
<?php
@mkdir('/tmp/phpc_suppress_hoist_gap');
$ctx = stream_context_create([]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'suppress_then_hoisted_empty_array.php');

        $suppressReturnSlot = null;
        $streamContextSendSlot = null;
        $walk = static function (Block $b) use (&$walk, &$suppressReturnSlot, &$streamContextSendSlot): void {
            $inSuppress = null !== $b->orig && $b->orig instanceof \PHPCfg\ErrorSuppressBlock;
            $inEnd = null !== $b->orig && 1 === \count($b->orig->parents) && $b->orig->parents[0] instanceof \PHPCfg\ErrorSuppressBlock;
            $pendingInit = false;
            foreach ($b->opCodes as $op) {
                if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                    $pendingInit = true;
                    continue;
                }
                if ($pendingInit && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                    if ($inSuppress) {
                        $suppressReturnSlot = $op->arg1;
                    }
                    $pendingInit = false;
                    continue;
                }
                if ($pendingInit && OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type) {
                    $pendingInit = false;
                    continue;
                }
                if (OpCode::TYPE_ARG_SEND === $op->type && $inEnd && null === $streamContextSendSlot) {
                    $streamContextSendSlot = $op->arg1;
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof Block) {
                        $walk($sub);
                    }
                }
            }
        };
        $walk($block);

        self::assertNotNull($suppressReturnSlot, 'suppress mkdir return slot');
        self::assertNotNull($streamContextSendSlot, 'stream_context_create arg send slot');
        self::assertNotSame(
            $suppressReturnSlot,
            $streamContextSendSlot,
            'hoisted [] must not alias unrelated @mkdir return slot'
        );
    }

    public function testErrorGetLastAfterStandaloneSuppressDoesNotAliasReturnSlotInCallArg(): void
    {
        $code = <<<'PHP'
<?php
@openssl_cipher_iv_length('nope');
$ivLast = error_get_last();
$ok = str_contains($ivLast['message'] ?? '', 'Unknown cipher algorithm');
echo $ok ? "ok\n" : "fail\n";
PHP;
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'error_get_last_after_suppress.php'));
        $out = ob_get_clean();

        self::assertSame("ok\n", $out);
    }

    public function testStrictTypesSuppressAssignNamedLocalCallArgUsesAssignLvalueSlot(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$v = @get_cfg_var('display_errors');
echo gettype($v), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'strict_suppress_assign_gettype.php');

        $suppressReturnSlot = null;
        $assignDestSlot = null;
        $gettypeSendSlot = null;
        $adjacentSelfCopyAssign = false;
        $walk = static function (Block $b) use (&$walk, &$suppressReturnSlot, &$assignDestSlot, &$gettypeSendSlot, &$adjacentSelfCopyAssign): void {
            $inSuppress = null !== $b->orig && $b->orig instanceof \PHPCfg\ErrorSuppressBlock;
            $inEnd = null !== $b->orig && 1 === \count($b->orig->parents) && $b->orig->parents[0] instanceof \PHPCfg\ErrorSuppressBlock;
            $pendingInit = false;
            $pendingCallee = null;
            foreach ($b->opCodes as $op) {
                if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                    $pendingInit = true;
                    $pendingCallee = $b->constants[$op->arg1] ?? null;
                    continue;
                }
                if ($pendingInit && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                    if ($inSuppress) {
                        $suppressReturnSlot = $op->arg1;
                    }
                    $pendingInit = false;
                    continue;
                }
                if ($pendingInit && OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type) {
                    $pendingInit = false;
                    continue;
                }
                if (OpCode::TYPE_ASSIGN === $op->type && $inEnd && null === $assignDestSlot) {
                    $assignDestSlot = $op->arg2;
                }
                if (OpCode::TYPE_ASSIGN === $op->type && $inEnd && $op->arg2 === $op->arg3) {
                    $adjacentSelfCopyAssign = true;
                }
                if (OpCode::TYPE_ARG_SEND === $op->type && $inEnd && null === $gettypeSendSlot) {
                    $gettypeSendSlot = $op->arg1;
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof Block) {
                        $walk($sub);
                    }
                }
            }
        };
        $walk($block);

        self::assertNotNull($suppressReturnSlot, 'suppress get_cfg_var return slot');
        self::assertNotNull($assignDestSlot, 'assign dest slot');
        self::assertSame($assignDestSlot, $gettypeSendSlot, 'gettype arg must read assign lvalue slot');
        self::assertFalse($adjacentSelfCopyAssign, 'must not emit arg2===arg3 adjacent assign sync');
    }

    public function testSuppressStatementThenNestedGettypeVarExportUsesGettypeReturnSlot(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);

enum E: int
{
    case A = 42;
}

$x = E::A;
@settype($x, 'int');
var_export($x);
echo "\n";
var_export(gettype($x));
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'suppress_settype_var_export_gettype.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();

        self::assertSame("1\n'integer'\n", $out);

        $suppressReturnSlots = [];
        $gettypeReturnSlot = null;
        $lastVarExportSendSlot = null;
        $walk = static function (Block $b) use (&$walk, &$suppressReturnSlots, &$gettypeReturnSlot, &$lastVarExportSendSlot): void {
            $inSuppress = null !== $b->orig && $b->orig instanceof \PHPCfg\ErrorSuppressBlock;
            $inEnd = null !== $b->orig && 1 === \count($b->orig->parents) && $b->orig->parents[0] instanceof \PHPCfg\ErrorSuppressBlock;
            $pendingInit = false;
            $pendingInEnd = false;
            foreach ($b->opCodes as $op) {
                if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                    $pendingInit = true;
                    $pendingInEnd = $inEnd;
                    continue;
                }
                if ($pendingInit && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                    if ($inSuppress) {
                        $suppressReturnSlots[] = $op->arg1;
                    } elseif ($pendingInEnd && null === $gettypeReturnSlot) {
                        $gettypeReturnSlot = $op->arg1;
                    }
                    $pendingInit = false;
                    continue;
                }
                if ($pendingInit && OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type) {
                    $pendingInit = false;
                    continue;
                }
                if (OpCode::TYPE_ARG_SEND === $op->type && $inEnd) {
                    $lastVarExportSendSlot = $op->arg1;
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof Block) {
                        $walk($sub);
                    }
                }
            }
        };
        $walk($block);

        self::assertNotEmpty($suppressReturnSlots, 'suppress settype return slot');
        self::assertNotNull($gettypeReturnSlot, 'gettype return slot');
        self::assertNotNull($lastVarExportSendSlot, 'var_export arg send slot');
        self::assertSame($gettypeReturnSlot, $lastVarExportSendSlot, 'var_export must read gettype return, not @settype');
        self::assertNotContains($lastVarExportSendSlot, $suppressReturnSlots, 'var_export must not alias @settype return');
    }

    public function testFilesystemIteratorInlineBitmaskFlagsAfterAtMkdirStayInt(): void
    {
        $dir = sys_get_temp_dir() . '/phpc_atfsi_ut_' . getmypid();
        @mkdir($dir);
        file_put_contents($dir . '/a.txt', '1');

        $code = <<<PHP
<?php
\$dir = {$this->exportPhpString($dir)};
@mkdir(\$dir);
\$it = new FilesystemIterator(\$dir, FilesystemIterator::CURRENT_AS_PATHNAME | FilesystemIterator::SKIP_DOTS);
echo json_encode(iterator_to_array(\$it, false));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'at_fsi_inline_bitmask.php');

        $mkdirReturnSlot = null;
        $bitwiseOrSlot = null;
        $flagsSendSlot = null;
        $iteratorToArrayReturnSlot = null;
        $jsonEncodeSendSlot = null;
        $walk = static function (Block $b) use (
            &$walk,
            &$mkdirReturnSlot,
            &$bitwiseOrSlot,
            &$flagsSendSlot,
            &$iteratorToArrayReturnSlot,
            &$jsonEncodeSendSlot
        ): void {
            $inSuppress = null !== $b->orig && $b->orig instanceof \PHPCfg\ErrorSuppressBlock;
            $pendingInit = null;
            $newArgOrdinal = -1;
            foreach ($b->opCodes as $op) {
                if (OpCode::TYPE_BITWISE_OR === $op->type) {
                    $bitwiseOrSlot = $op->arg1;
                }
                if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                    $pendingInit = true;
                    $newArgOrdinal = -1;
                    continue;
                }
                if (OpCode::TYPE_NEW === $op->type) {
                    $newArgOrdinal = 0;
                    $pendingInit = false;
                    continue;
                }
                if ($pendingInit && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                    if ($inSuppress && null === $mkdirReturnSlot) {
                        $mkdirReturnSlot = $op->arg1;
                    } elseif (null === $iteratorToArrayReturnSlot && null !== $bitwiseOrSlot) {
                        $iteratorToArrayReturnSlot = $op->arg1;
                    }
                    $pendingInit = false;
                    continue;
                }
                if (OpCode::TYPE_ARG_SEND === $op->type) {
                    if ($newArgOrdinal >= 0) {
                        if (1 === $newArgOrdinal) {
                            $flagsSendSlot = $op->arg1;
                        }
                        ++$newArgOrdinal;
                    } elseif (null !== $iteratorToArrayReturnSlot && null === $jsonEncodeSendSlot) {
                        $jsonEncodeSendSlot = $op->arg1;
                    }
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof Block) {
                        $walk($sub);
                    }
                }
            }
        };
        $walk($block);

        self::assertNotNull($bitwiseOrSlot, 'inline CONST|CONST must emit BITWISE_OR');
        self::assertNotNull($flagsSendSlot, 'FilesystemIterator flags ARG_SEND');
        self::assertSame($bitwiseOrSlot, $flagsSendSlot, 'flags ARG_SEND must use BITWISE_OR result, not @mkdir bool');
        self::assertNotSame($mkdirReturnSlot, $flagsSendSlot, 'flags must not alias @mkdir return');
        self::assertNotNull($iteratorToArrayReturnSlot, 'iterator_to_array return');
        self::assertNotNull($jsonEncodeSendSlot, 'json_encode ARG_SEND');
        self::assertSame(
            $iteratorToArrayReturnSlot,
            $jsonEncodeSendSlot,
            'json_encode must read iterator_to_array, not stale bitmask OR (#24369)'
        );

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame(json_encode([$dir . '/a.txt']), $out);

        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    public function testNestedNewDirectoryIteratorAfterAtMkdirUsesInnerObjectNotBool(): void
    {
        $dir = sys_get_temp_dir() . '/phpc_atndi_ut_' . getmypid();
        @mkdir($dir);
        file_put_contents($dir . '/x.txt', '1');

        $code = <<<PHP
<?php
\$dir = {$this->exportPhpString($dir)};
@mkdir(\$dir);
\$it = new IteratorIterator(new DirectoryIterator(\$dir));
\$n = 0;
foreach (\$it as \$f) { \$n++; }
echo "count=\$n\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'at_nested_new_di.php');

        $mkdirReturnSlot = null;
        $innerCtorReturnSlot = null;
        $outerArgSendSlot = null;
        $seenNews = 0;
        $capture = static function (Block $b) use (
            &$capture,
            &$mkdirReturnSlot,
            &$innerCtorReturnSlot,
            &$outerArgSendSlot,
            &$seenNews
        ): void {
            $inSuppress = null !== $b->orig && $b->orig instanceof \PHPCfg\ErrorSuppressBlock;
            $pendingInit = false;
            $inCtorAfterNew = false;
            foreach ($b->opCodes as $op) {
                if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                    $pendingInit = true;
                    continue;
                }
                if (OpCode::TYPE_NEW === $op->type) {
                    ++$seenNews;
                    $inCtorAfterNew = true;
                    $pendingInit = false;
                    continue;
                }
                if ($pendingInit && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                    if ($inSuppress && null === $mkdirReturnSlot) {
                        $mkdirReturnSlot = $op->arg1;
                    }
                    $pendingInit = false;
                    continue;
                }
                if ($inCtorAfterNew && OpCode::TYPE_ARG_SEND === $op->type) {
                    if (2 === $seenNews && null === $outerArgSendSlot) {
                        $outerArgSendSlot = $op->arg1;
                    }
                    continue;
                }
                if ($inCtorAfterNew && OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                    if (1 === $seenNews) {
                        $innerCtorReturnSlot = $op->arg1;
                    }
                    $inCtorAfterNew = false;
                    continue;
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof Block) {
                        $capture($sub);
                    }
                }
            }
        };
        $capture($block);

        self::assertNotNull($mkdirReturnSlot, '@mkdir return slot');
        self::assertNotNull($innerCtorReturnSlot, 'inner DirectoryIterator result slot');
        self::assertNotNull($outerArgSendSlot, 'IteratorIterator ARG_SEND');
        self::assertSame(
            $innerCtorReturnSlot,
            $outerArgSendSlot,
            'outer new arg must be inner DirectoryIterator, not @mkdir bool (#24368)'
        );
        self::assertNotSame($mkdirReturnSlot, $outerArgSendSlot, 'outer arg must not alias @mkdir return');

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("count=3\n", $out);

        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    private function exportPhpString(string $value): string
    {
        return var_export($value, true);
    }
}
