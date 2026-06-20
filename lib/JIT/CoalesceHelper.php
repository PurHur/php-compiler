<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\BasicBlock;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_;
use PHPCompiler\Block;
use PHPCompiler\JIT;
use PHPCompiler\OpCode;

/**
 * LLVM lowering helpers for ?? (null coalescing) branch targets (issue #99, #10171, #10311).
 *
 * SSOT: {@see \PHPCompiler\VM\CoalesceJitHelper}
 */
final class CoalesceHelper
{
    private const HELPER_PATH = '/VM/CoalesceJitHelper.php';

    private const TAKE_LEFT_HELPER = 'PHPCompiler\\VM\\CoalesceJitHelper::takeLeftBranchFromTypeByte';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::TAKE_LEFT_HELPER,
    ];

    public static function compileBranch(
        JIT $jit,
        Function_ $func,
        Block $branchBlock
    ): BasicBlock {
        return $jit->compileSubBlock($func, $branchBlock);
    }

    /**
     * i1: take ?? left branch (ISSET bool or value-box type byte via CoalesceJitHelper PHP).
     */
    public static function isTakeLeftBranch(JIT $jit, Variable $check): Value
    {
        $context = $jit->context;
        if (Variable::TYPE_NATIVE_BOOL === $check->type) {
            return $context->helper->loadValue($check);
        }
        if (Variable::TYPE_VALUE === $check->type) {
            self::ensureLinked($context);
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $check);
            $typeByte = $context->builder->load(
                $context->builder->structGep(
                    $valuePtr,
                    $context->structFieldMap['__value__']['type']
                )
            );

            return self::callTakeLeftBranch($context, $typeByte);
        }

        return $context->castToBool($context->helper->loadValue($check));
    }

    /**
     * Opcode limit for a ?? / ?-> merge CFG block.
     *
     * Nested chains ($a ?? $b ?? $c) use an inner merge stub (single ASSIGN + JUMP to the outer
     * continuation). Compiling that JUMP eagerly lowers the outer block before both ?? arms merge
     * (#3798, #4764). Wider merge blocks (echo/return after ??) keep their trailing JUMP.
     */
    public static function mergeBlockOpcodeLimit(Block $mergeBlock): ?int
    {
        $limit = $mergeBlock->nOpCodes;
        if (
            2 === $limit
            && OpCode::TYPE_JUMP === $mergeBlock->opCodes[1]->type
            && OpCode::TYPE_ASSIGN === $mergeBlock->opCodes[0]->type
        ) {
            return 1;
        }

        return $limit > 0 ? $limit : null;
    }

    private static function ensureLinked(Context $context): void
    {
        $abiName = '__coalesce__takeLeftBranch';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            $abiName,
            'coalesce_take_left_bridge_entry',
            [$i8],
            $i1,
            self::TAKE_LEFT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#10311'
        );
        $context->builder->clearInsertionPosition();
    }

    private static function callTakeLeftBranch(Context $context, Value $typeByte): Value
    {
        self::ensureLinked($context);
        $fn = $context->lookupFunction('__coalesce__takeLeftBranch');
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->call(
            $fn,
            $context->builder->trunc($typeByte, $i8)
        );
    }
}
