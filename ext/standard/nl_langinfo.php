<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * nl_langinfo() — locale item lookup (issue #3382).
 *
 * php-src: ext/standard/nl_langinfo.c — PHP_FUNCTION(nl_langinfo)
 */
final class nl_langinfo extends Internal
{
    public function __construct()
    {
        parent::__construct('nl_langinfo');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                \sprintf('nl_langinfo() expects exactly 1 argument, %d given', \count($frame->calledArgs))
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $itemVar = $frame->calledArgs[0]->resolveIndirect();
        $result = VmLocale::nlLanginfo(self::parseItemArg($itemVar));
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('nl_langinfo() expects exactly one argument in this compiler build');
        }

        return JitNlLanginfo::invoke($context, $args[0]);
    }

    private static function parseItemArg(Variable $var): int
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(self::itemTypeError(EnumCaseSupport::typeNameForVariable($var)));
        }

        throw new \TypeError(self::itemTypeError(self::vmTypeName($var->type)));
    }

    private static function itemTypeError(string $given): string
    {
        return \sprintf(
            'nl_langinfo(): Argument #1 ($item) must be of type int, %s given',
            $given
        );
    }

    private static function vmTypeName(int $type): string
    {
        return match ($type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }
}
