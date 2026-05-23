<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** wordwrap() — wrap a string to a given width (subset of PHP). */
final class wordwrap extends Internal
{
    public function __construct()
    {
        parent::__construct('wordwrap');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('wordwrap() requires one to four arguments in this compiler build');
        }
        $text = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $text->type) {
            throw new \LogicException('wordwrap() first argument must be a string in this compiler build');
        }
        $width = 75;
        if ($argc >= 2) {
            $widthArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $widthArg->type) {
                throw new \LogicException('wordwrap() width must be an integer in this compiler build');
            }
            $width = $widthArg->toInt();
        }
        $break = "\n";
        if ($argc >= 3) {
            $breakArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_STRING !== $breakArg->type) {
                throw new \LogicException('wordwrap() break must be a string in this compiler build');
            }
            $break = $breakArg->toString();
        }
        $cut = false;
        if (4 === $argc) {
            $cutArg = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $cutArg->type) {
                throw new \LogicException('wordwrap() cut must be a boolean in this compiler build');
            }
            $cut = $cutArg->toBool();
        }
        $frame->returnVar->string(
            VmString::wordwrap($text->toString(), $width, $break, $cut)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('wordwrap() requires one to four arguments in this compiler build');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('wordwrap() first argument must be a string in this compiler build');
        }
        $input = $context->helper->loadValue($args[0]);
        $i64 = $context->getTypeFromString('int64');
        $width = $i64->constInt(75, false);
        if ($argc >= 2) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
                throw new \LogicException('wordwrap() width must be an integer in this compiler build');
            }
            $width = $context->helper->loadValue($args[1]);
        }
        if ($argc >= 3) {
            if (JITVariable::TYPE_STRING !== $args[2]->type) {
                throw new \LogicException('wordwrap() break must be a string in this compiler build');
            }
            $break = $context->helper->loadValue($args[2]);
        } else {
            $break = $context->builder->load($context->constantStringFromString("\n"));
        }
        $i8 = $context->getTypeFromString('int8');
        $cut = $i8->constInt(0, false);
        if (4 === $argc) {
            if (JITVariable::TYPE_NATIVE_BOOL !== $args[3]->type) {
                throw new \LogicException('wordwrap() cut must be a boolean in this compiler build');
            }
            $cut = $context->builder->zExt($context->helper->loadValue($args[3]), $i8);
        }

        return JitWordwrap::wrap($context, $input, $width, $break, $cut);
    }
}
