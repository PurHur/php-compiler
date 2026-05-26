<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringHtmlspecialcharsDecode;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * htmlspecialchars_decode() for strings (subset of PHP; JIT/AOT via phpc_htmlspecialchars_decode.c).
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
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('htmlspecialchars_decode() requires one to three arguments');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('htmlspecialchars_decode() only supports strings in this compiler build');
        }
        $string = $v->toString();
        $flags = ENT_COMPAT;
        $encoding = 'UTF-8';
        if ($argc >= 2) {
            $flagsVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagsVar->type) {
                throw new \LogicException('htmlspecialchars_decode() flags must be an integer in this compiler build');
            }
            $flags = $flagsVar->toInt();
        }
        if (3 === $argc) {
            $encVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_STRING !== $encVar->type) {
                throw new \LogicException('htmlspecialchars_decode() encoding must be a string in this compiler build');
            }
            $encoding = $encVar->toString();
        }
        $frame->returnVar->string(VmString::htmlspecialchars_decode($string, $flags, $encoding));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        StringHtmlspecialcharsDecode::ensureLinked($context);
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException(
                'htmlspecialchars_decode() JIT only supports string and optional flags in this compiler build'
            );
        }
        if ($argc >= 2 && JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
            throw new \LogicException('htmlspecialchars_decode() flags must be an integer in this compiler build');
        }

        $literal = null;
        if (JITVariable::TYPE_VALUE !== $args[0]->type) {
            $maybeLiteral = $args[0]->compileTimeString ?? null;
            if (null !== $maybeLiteral && JITVariable::KIND_VALUE === $args[0]->kind) {
                $literal = $maybeLiteral;
            }
        }
        if (null !== $literal && 1 === $argc) {
            return $context->builder->load(
                $context->constantStringFromString(
                    VmString::htmlspecialchars_decode($literal, ENT_COMPAT, 'UTF-8')
                )
            );
        }

        $str = JitStringArg::lower($context, $args[0], 'htmlspecialchars_decode() string');
        $flags = $context->getTypeFromString('int64')->constInt(ENT_COMPAT, false);
        if ($argc >= 2) {
            $flags = $context->helper->loadValue($args[1]);
        }
        $fn = $context->lookupFunction('phpc_htmlspecialchars_decode');

        return $context->builder->call(
            $fn,
            $this->stringDataPtr($context, $str),
            $flags
        );
    }
}
