<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\BuiltinParamNames;
use PHPCompiler\JIT\Builtin\ReflectionInternalFunctionLowering;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Value;

/**
 * ReflectionParameter::__construct($function, $parameter) — JIT/AOT thin path (#33993).
 *
 * Mirrors VM {@see \PHPCompiler\VM\Builtin\ReflectionParameterConstruct} for the common
 * string function + string|int parameter shape. php-src: zim_ReflectionParameter___construct
 * (ext/reflection/php_reflection.c).
 */
final class ReflectionParameterConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 3) {
            ReflectionSupport::throwConstructArgumentCountError(
                'ReflectionParameter',
                2,
                max(0, \max(0, count($args) - 1))
            );
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        if ($this->tryInitInternalFunctionByLiteralIndex($context, $obj, $args[1], $args[2])) {
            if (null !== ($args[1]->compileTimeString ?? null)) {
                ReflectionInternalFunctionLowering::recordFunction($args[1]->compileTimeString);
            }

            return $this->nullReturnSlot($context);
        }

        // Public Zend surface is `$name`; also stash declaring function for getName/is* (#22528).
        ReflectionSetup::emitSetStringPropertyFromVar(
            $context,
            $obj,
            'ReflectionParameter',
            ReflectionSupport::PROP_FUNC_NAME,
            $args[1]
        );
        ReflectionSetup::emitSetStringPropertyFromVar(
            $context,
            $obj,
            'ReflectionParameter',
            ReflectionSupport::PROP_PARAM_NAME,
            $args[2]
        );
        ReflectionSetup::markConstructed($context, $obj);

        return $this->nullReturnSlot($context);
    }

    private function tryInitInternalFunctionByLiteralIndex(
        Context $context,
        Value $obj,
        Variable $functionArg,
        Variable $parameterArg,
    ): bool {
        $funcLit = $functionArg->compileTimeString;
        $indexLit = $parameterArg->compileTimeLong;
        if (null === $funcLit || null === $indexLit) {
            return false;
        }
        $paramNames = BuiltinParamNames::paramNamesForInternalFunction($funcLit);
        if (null === $paramNames || !isset($paramNames[$indexLit])) {
            return false;
        }

        $displayName = ltrim((string) $paramNames[$indexLit], '&');
        if (str_starts_with($displayName, '...')) {
            $displayName = substr($displayName, 3);
        }
        $displayName = rtrim($displayName, '=');

        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $emptyCstr = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $zeroLen = $sizeT->constInt(0, false);
        ReflectionSetup::emitSetStringPropertyFromCstr(
            $context,
            $obj,
            'ReflectionParameter',
            ReflectionSupport::PROP_PARAM_CLASS,
            $emptyCstr,
            $zeroLen
        );
        ReflectionSetup::emitSetStringPropertyFromCstr(
            $context,
            $obj,
            'ReflectionParameter',
            ReflectionSupport::PROP_METHOD_NAME,
            $emptyCstr,
            $zeroLen
        );
        $funcCstr = $context->builder->pointerCast($context->constantFromString($funcLit), $i8p);
        $nameCstr = $context->builder->pointerCast($context->constantFromString($displayName), $i8p);
        ReflectionSetup::emitSetStringPropertyFromCstr(
            $context,
            $obj,
            'ReflectionParameter',
            ReflectionSupport::PROP_FUNC_NAME,
            $funcCstr,
            $sizeT->constInt(\strlen($funcLit), false)
        );
        ReflectionSetup::emitSetStringPropertyFromCstr(
            $context,
            $obj,
            'ReflectionParameter',
            ReflectionSupport::PROP_PARAM_NAME,
            $nameCstr,
            $sizeT->constInt(\strlen($displayName), false)
        );
        ReflectionSetup::emitSetIntegerProperty(
            $context,
            $obj,
            'ReflectionParameter',
            ReflectionSupport::PROP_PARAM_INDEX,
            $indexLit
        );
        ReflectionSetup::emitSetIntegerProperty(
            $context,
            $obj,
            'ReflectionParameter',
            ReflectionSupport::PROP_PARAM_POSITION,
            $indexLit
        );
        ReflectionSetup::markConstructed($context, $obj);

        return true;
    }

    private function nullReturnSlot(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
