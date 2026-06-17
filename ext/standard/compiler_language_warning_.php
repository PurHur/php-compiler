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
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * Emit Zend-style E_WARNING for language constructs lowered in php-cfg (issue #4502).
 *
 * php-src: Zend/zend_compile.c — continue targeting switch warning.
 */
final class compiler_language_warning_ extends Internal
{
    public function __construct()
    {
        parent::__construct('compiler_language_warning');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('compiler_language_warning() requires VM context');
        }
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('compiler_language_warning() requires one or two arguments');
        }
        $messageVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $messageVar->type) {
            throw new \LogicException('compiler_language_warning() message must be a string');
        }
        $line = 0;
        if (2 === $argc) {
            $lineVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $lineVar->type) {
                throw new \LogicException('compiler_language_warning() line must be an integer');
            }
            $line = $lineVar->toInt();
        }
        $file = '' !== $frame->scriptPath
            ? $frame->scriptPath
            : ('' !== $frame->vmContext->scriptStack->current()
                ? $frame->vmContext->scriptStack->current()
                : null);
        $frame->vmContext->errors->languageWarning(
            $messageVar->toString(),
            $file,
            $line,
            $frame->vmContext,
            $frame
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('compiler_language_warning() requires one or two arguments');
        }
        $msgStr = JitStringBuiltinArg::lower($context, $args[0], 'compiler_language_warning', 0, 'message');
        $lineVal = 2 === $argc
            ? $this->jitLowerLine($context, $args[1])
            : $context->getTypeFromString('int32')->constInt($context->callSiteLine, false);
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
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $filePtr,
            $lineVal
        );
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt(1, false);
    }

    private function jitLowerLine(Context $context, JITVariable $arg): Value
    {
        if (null !== ($arg->compileTimeLong ?? null)) {
            return $context->getTypeFromString('int32')->constInt((int) $arg->compileTimeLong, false);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            $lib = $context->llvm->lib;
            if (null !== $arg->value && null !== $lib->LLVMIsAConstantInt($arg->value->value)) {
                return $context->getTypeFromString('int32')->constInt(
                    (int) $lib->LLVMConstIntGetSExtValue($arg->value->value),
                    false
                );
            }
        }

        return $context->builder->trunc(
            $this->jitLong($context, $arg, 'compiler_language_warning() line'),
            $context->getTypeFromString('int32')
        );
    }
}
