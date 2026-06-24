<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** wordwrap() — wrap string to width (subset of PHP; JIT/AOT via __string__wordwrap). */
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
        if (null === $frame->returnVar) {
            return;
        }
        InternalStrictArg::rejectNullString($frame->calledArgs[0], 'wordwrap', 'string', 0, $frame);
        $text = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'wordwrap',
            0,
            'string'
        );
        $width = 75;
        if ($argc >= 2) {
            $width = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[1],
                'wordwrap',
                2,
                'width'
            );
        }
        $break = "\n";
        if ($argc >= 3) {
            $break = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[2],
                'wordwrap',
                2,
                'break'
            );
        }
        $cut = false;
        if (4 === $argc) {
            $c = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $c->type) {
                throw new \LogicException('wordwrap() cut must be a boolean in this compiler build');
            }
            $cut = $c->toBool();
        }
        $frame->returnVar->string(
            VmString::wordwrap($text, $width, $break, $cut)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('wordwrap() requires one to four arguments in this compiler build');
        }
        JitInternalStrictArg::rejectNullString($context, $args[0], 'wordwrap', 'string', 1);
        $input = JitStringBuiltinArg::lower($context, $args[0], 'wordwrap', 0, 'string');
        $i64 = $context->getTypeFromString('int64');
        $width = $i64->constInt(75, false);
        if ($argc >= 2) {
            $width = JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'wordwrap', 2, 'width');
        }
        if ($argc >= 3) {
            $break = JitStringBuiltinArg::lower($context, $args[2], 'wordwrap', 2, 'break');
        } else {
            $break = $context->builder->load($context->constantStringFromString("\n"));
        }
        $i8 = $context->getTypeFromString('int8');
        $cutI8 = $i8->constInt(0, false);
        if (4 === $argc) {
            if (JITVariable::TYPE_NATIVE_BOOL !== $args[3]->type) {
                throw new \LogicException('wordwrap() cut must be a boolean in this compiler build');
            }
            $cutI8 = $context->builder->zExt($context->helper->loadValue($args[3]), $i8);
        }

        return JitWordwrap::wrap($context, $input, $width, $break, $cutI8, $args[0], $argc >= 2 ? $args[1] : null, $argc >= 3 ? $args[2] : null, 4 === $argc ? $args[3] : null);
    }
}
