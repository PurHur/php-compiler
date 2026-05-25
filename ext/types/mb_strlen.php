<?php

declare(strict_types=1);

namespace PHPCompiler\ext\types;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM\Value;

/**
 * mb_strlen() — UTF-8 character count for web forms (issue #158).
 */
final class mb_strlen extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_strlen');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('mb_strlen() requires one or two arguments');
        }
        $strVar = $frame->calledArgs[0]->resolveIndirect();
        if (VMVariable::TYPE_STRING !== $strVar->type) {
            throw new \LogicException('mb_strlen() only supports strings in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $str = $strVar->toString();
        $encoding = 'UTF-8';
        if (2 === $argc) {
            $encVar = $frame->calledArgs[1]->resolveIndirect();
            if (VMVariable::TYPE_STRING !== $encVar->type) {
                throw new \LogicException('mb_strlen() encoding must be a string in this compiler build');
            }
            $encoding = $encVar->toString();
        }
        $frame->returnVar->int(self::lengthForEncoding($str, $encoding));
    }

    public function call(Context $context, Variable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('mb_strlen() requires one or two arguments');
        }
        if (1 === $argc) {
            return JitMbStrlen::utf8Length($context, $args[0]);
        }
        if (Variable::TYPE_STRING !== $args[1]->type) {
            throw new \LogicException('mb_strlen() encoding must be a string in this compiler build');
        }
        $encoding = $args[1]->compileTimeString ?? null;
        if ('UTF-8' === $encoding) {
            return JitMbStrlen::utf8Length($context, $args[0]);
        }
        if (null !== $encoding && 'ASCII' !== $encoding && '8BIT' !== $encoding) {
            throw new \LogicException(
                'mb_strlen() JIT only supports UTF-8, ASCII, or 8BIT encoding literals in this compiler build'
            );
        }
        if (Variable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('mb_strlen() only supports strings in this compiler build');
        }
        $argValue = $context->helper->loadValue($args[0]);
        $offset = $context->structFieldIndex($argValue, 'length');

        return $context->builder->load(
            $context->builder->structGep($argValue, $offset)
        );
    }

    private static function lengthForEncoding(string $str, string $encoding): int
    {
        if ('UTF-8' === $encoding) {
            if (\function_exists('mb_strlen')) {
                return (int) \mb_strlen($str, 'UTF-8');
            }

            return VmString::utf8CharLength($str);
        }
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            return VmString::byteLength($str);
        }
        if (\function_exists('mb_strlen')) {
            return (int) \mb_strlen($str, $encoding);
        }

        throw new \LogicException(
            'mb_strlen() requires mbstring for encoding '.$encoding.' in this compiler build'
        );
    }
}
