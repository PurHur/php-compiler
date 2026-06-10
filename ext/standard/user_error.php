<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\ScriptMagic;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\OpCode;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/**
 * user_error() — PHP 8.4 procedural E_USER_NOTICE helper (ext/standard/basic_functions.c, #6183).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/basic_functions.c PHP_FUNCTION(user_error)
 */
final class user_error extends Internal
{
    public function __construct()
    {
        parent::__construct('user_error');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                \sprintf('user_error() expects exactly 1 argument, %d given', \count($frame->calledArgs))
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('user_error() requires VM context');
        }
        $message = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'user_error', 0, 'message');
        $file = null;
        $line = 0;
        $caller = $frame->parent;
        if (null !== $caller) {
            if ('' !== $caller->scriptPath) {
                $file = $caller->scriptPath;
            }
            $line = $caller->callSiteLine;
        } elseif ('' !== $frame->scriptPath) {
            $file = $frame->scriptPath;
        }
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_USER_NOTICE,
            $file,
            $frame->vmContext,
            $frame,
            $line
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                \sprintf('user_error() expects exactly 1 argument, %d given', \count($args))
            );
        }
        $msgStr = JitStringBuiltinArg::lower($context, $args[0], 'user_error', 0, 'message');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $map = $context->structFieldMap['__string__'];
        $msgLen = $context->builder->load(
            $context->builder->structGep($msgStr, $map['length'])
        );
        $msgLen = $context->builder->zExt(
            $context->builder->trunc($msgLen, $i32),
            $sizeT
        );
        $msgPtr = $context->builder->structGep($msgStr, $map['value']);
        $filePath = '';
        if (null !== $context->jitEnclosingBlock) {
            $filePath = ScriptMagic::stringForBlock($context->jitEnclosingBlock, OpCode::SCRIPT_MAGIC_FILE);
        }
        $filePtr = $context->builder->pointerCast($context->constantFromString($filePath), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_USER_NOTICE, false),
            $filePtr,
            $i32->constInt($context->callSiteLine, false)
        );
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt(1, false);
    }
}
