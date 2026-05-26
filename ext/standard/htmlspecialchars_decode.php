<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * htmlspecialchars_decode() — decode HTML entities (subset of PHP; issue #2454).
 *
 * VM: {@see VmString::htmlspecialchars_decode()}; JIT/AOT: phpc_htmlspecialchars_decode.c.
 */
final class htmlspecialchars_decode extends Internal
{
    public function __construct()
    {
        parent::__construct('htmlspecialchars_decode');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('htmlspecialchars_decode() requires one or two arguments in this compiler build');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('htmlspecialchars_decode() only supports strings in this compiler build');
        }
        $flags = ENT_QUOTES | ENT_SUBSTITUTE;
        if ($argc >= 2) {
            $flagsVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagsVar->type) {
                throw new \LogicException('htmlspecialchars_decode() flags must be an integer in this compiler build');
            }
            $flags = $flagsVar->toInt();
        }
        $frame->returnVar->string(VmString::htmlspecialchars_decode($v->toString(), $flags));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('htmlspecialchars_decode() requires one or two arguments in this compiler build');
        }

        $literal = null;
        if (JITVariable::TYPE_VALUE !== $args[0]->type) {
            $maybeLiteral = $args[0]->compileTimeString ?? null;
            if (null !== $maybeLiteral && JITVariable::KIND_VALUE === $args[0]->kind) {
                $literal = $maybeLiteral;
            }
        }
        $flags = ENT_QUOTES | ENT_SUBSTITUTE;
        if ($argc >= 2) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
                throw new \LogicException('htmlspecialchars_decode() flags must be an integer in this compiler build');
            }
            $ct = $args[1]->compileTimeLong ?? null;
            if (null !== $ct) {
                $flags = (int) $ct;
            }
        }
        if (null !== $literal && 1 === $argc) {
            return $context->builder->load(
                $context->constantStringFromString(
                    VmString::htmlspecialchars_decode($literal, $flags)
                )
            );
        }

        $str = JitStringArg::lower($context, $args[0], 'htmlspecialchars_decode() argument #1');
        $i64 = $context->getTypeFromString('int64');
        $flagsVal = $i64->constInt($flags, false);
        if ($argc >= 2 && null === ($args[1]->compileTimeLong ?? null)) {
            $flagsVal = $context->helper->loadValue($args[1]);
        }

        return JitHtmlspecialcharsDecode::decode($context, $str, $flagsVal);
    }
}
