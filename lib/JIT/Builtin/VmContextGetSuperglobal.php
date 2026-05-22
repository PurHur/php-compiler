<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\SuperglobalInit;
use PHPCompiler\JIT\Variable;
use PHPCompiler\Web\Superglobals;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class VmContextGetSuperglobal implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (count($args) < 1) {
            throw new \LogicException('Context::getSuperglobal() expects at least one argument');
        }
        $nameArg = $args[count($args) - 1];
        if (null !== $nameArg->compileTimeString) {
            $name = $nameArg->compileTimeString;
            if (!isset(SuperglobalInit::$globals[$name])) {
                throw new \LogicException("Superglobal not initialized for JIT: {$name}");
            }

            return $context->builder->load(SuperglobalInit::$globals[$name]);
        }
        return $context->getTypeFromString('__hashtable__*')->constNull();
    }

    private static function stringPtr(Context $context, Variable $arg): Value
    {
        if (Variable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            $valPtr = Variable::KIND_VARIABLE === $arg->kind
                ? JitValueBox::pointer($context, $arg->value)
                : $context->helper->loadValue($arg);

            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $valPtr
            );
        }
        throw new \LogicException(
            'Context::getSuperglobal() requires a string name, got '
            .Variable::getStringType($arg->type)
        );
    }
}
