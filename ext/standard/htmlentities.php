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
 * htmlentities() — same entity subset as htmlspecialchars(); default ENT_COMPAT (#2472).
 */
final class htmlentities extends Internal
{
    public function __construct()
    {
        parent::__construct('htmlentities');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('htmlentities() requires one to four arguments in this compiler build');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('htmlentities() only supports strings in this compiler build');
        }
        $string = $v->toString();
        $flags = ENT_COMPAT;
        $encoding = 'UTF-8';
        $doubleEncode = true;
        if ($argc >= 2) {
            $flagsVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagsVar->type) {
                throw new \LogicException('htmlentities() flags must be an integer in this compiler build');
            }
            $flags = $flagsVar->toInt();
        }
        if ($argc >= 3) {
            $encVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_STRING !== $encVar->type) {
                throw new \LogicException('htmlentities() encoding must be a string in this compiler build');
            }
            $encoding = $encVar->toString();
        }
        if (4 === $argc) {
            $deVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $deVar->type) {
                throw new \LogicException('htmlentities() double_encode must be a boolean in this compiler build');
            }
            $doubleEncode = $deVar->toBool();
        }
        $frame->returnVar->string(VmString::htmlentities($string, $flags, $encoding, $doubleEncode));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = \count($args);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('htmlentities() requires one to four arguments in this compiler build');
        }
        if ($argc >= 3) {
            throw new \LogicException(
                'htmlentities() JIT only supports string and optional flags in this compiler build'
            );
        }

        if ($argc >= 2 && JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
            throw new \LogicException('htmlentities() flags must be an integer in this compiler build');
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
                    VmString::htmlentities($literal, ENT_COMPAT, 'UTF-8', true)
                )
            );
        }

        $str = JitStringArg::lower($context, $args[0], 'htmlentities() string');
        $flags = $context->getTypeFromString('int64')->constInt(ENT_COMPAT, false);
        if ($argc >= 2) {
            $flags = $context->helper->loadValue($args[1]);
        }

        return JitHtmlentities::escape($context, $str, $flags);
    }
}
