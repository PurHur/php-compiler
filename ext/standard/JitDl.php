<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringTriggerError;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/**
 * LLVM lowering for dl() stub — enable_dl off, return false + E_WARNING (#3591, #30250).
 *
 * Z_PARAM_STR via lowerStrictOrCoercible — TypeError under caller strict_types before Warning.
 */
final class JitDl
{
    private const MSG_ENABLE_DL_OFF = 'Dynamically loaded extensions aren\'t enabled';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                'dl() expects exactly 1 argument, '.\max(0, \count($args) - 1).' given'
            );
        }

        // Z_PARAM_STR — soft-null DEP outside strict_types; TypeError under strict (#30250).
        JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[0],
            'dl',
            0,
            'extension_filename',
            'string',
            null,
            false
        );
        // declare(strict_types=1): null rejects via lowerStrictOrCoercible — do not emit Warning (#30250).
        if ($context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))
        ) {
            // Catchable TypeError seals the insert block; open a dead BB so later try-body
            // ops (e.g. echo after dl(null)) do not land after a terminator (#30250).
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dl_strict_null_dead');
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        self::emitWarning($context);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

        return $ptr;
    }

    private static function emitWarning(Context $context): void
    {
        StringTriggerError::ensureLinked($context);
        $msg = self::MSG_ENABLE_DL_OFF;
        $msgStr = $context->builder->pointerCast(
            $context->constantFromString($msg),
            $context->getTypeFromString('int8*')
        );
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $context->getTypeFromString('int8*'));
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgStr,
            $sizeT->constInt(\strlen($msg), false),
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }
}
