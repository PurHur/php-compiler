<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\Web\Superglobals;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class SuperglobalNameCheck implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (1 !== count($args)) {
            throw new \LogicException('Superglobals::isSuperglobalName() expects one argument');
        }
        $arg = $args[0];
        if (null !== $arg->compileTimeString) {
            return $context->constantFromBool(Superglobals::isSuperglobalName($arg->compileTimeString));
        }
        if (Variable::TYPE_STRING === $arg->type) {
            return self::matchString($context, $context->helper->loadValue($arg));
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return $context->constantFromBool(false);
        }
        return $context->constantFromBool(false);
    }

    private static function matchString(Context $context, Value $str): Value
    {
        $i1 = $context->getTypeFromString('int1');
        $false = $i1->constInt(0, false);
        $true = $i1->constInt(1, false);
        $result = $false;
        foreach (Superglobals::NAMES as $name) {
            $lit = $context->constantStringFromString($name);
            $same = JitStringCompare::identical($context, $str, $lit);
            $result = $context->builder->select($same, $true, $result);
        }

        return $result;
    }
}
