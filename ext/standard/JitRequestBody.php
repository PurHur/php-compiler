<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for php://input — libc getenv("REQUEST_BODY") as __string__*.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringGetenv;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitRequestBody
{
    private static int $blockSerial = 0;

    public static function readPhpInput(Context $context): Value
    {
        StringGetenv::ensureLibcGetenv($context);
        $id = (string) (++self::$blockSerial);
        $map = $context->structFieldMap['__string__'];
        $nameStr = $context->builder->load($context->constantStringFromString('REQUEST_BODY'));
        $namePtr = $context->builder->structGep($nameStr, $map['value']);
        $i8p = $context->getTypeFromString('int8*');
        $env = $context->builder->call(
            $context->lookupFunction('getenv'),
            $context->builder->pointerCast($namePtr, $i8p)
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $env, $i8p->constNull());

        $emptyBlock = BasicBlockHelper::append($context, 'php_input_empty_'.$id);
        $bodyBlock = BasicBlockHelper::append($context, 'php_input_body_'.$id);
        $mergeBlock = BasicBlockHelper::append($context, 'php_input_merge_'.$id);
        $context->builder->branchIf($isNull, $emptyBlock, $bodyBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyStr = $context->builder->load($context->constantStringFromString(''));
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($bodyBlock);
        $i64 = $context->getTypeFromString('int64');
        $len = $context->builder->call($context->lookupFunction('strlen'), $env);
        $lenI64 = $context->builder->zExt($len, $i64);
        $bodyStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $env
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $strType = $emptyStr->typeOf();
        $phi = $context->builder->phi($strType);
        $phi->addIncoming($emptyStr, $emptyBlock);
        $phi->addIncoming($bodyStr, $bodyBlock);

        $resultSlot = $context->builder->alloca($strType, 1, 'php_input_result_'.$id);
        $context->builder->store($phi, $resultSlot);
        $contBlock = BasicBlockHelper::append($context, 'php_input_cont_'.$id);
        $context->builder->branch($contBlock);
        $context->builder->positionAtEnd($contBlock);

        return $context->builder->load($resultSlot);
    }
}
