<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Value;

/** ReflectionNamedType::__toString() — JIT/AOT (#28780). */
final class ReflectionNamedTypeToString implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ReflectionNamedType::__toString()', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        return self::typeString($context, $args[0], 'ReflectionNamedType');
    }

    public static function typeString(Context $context, Variable $receiver, string $className): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'refl_namedtype_tostring_cont');
        $obj = ReflectionSetup::loadObjectFromArg($context, $receiver);
        $propVar = $context->type->object->propertyFetch(
            $obj,
            $className,
            ReflectionSupport::PROP_TYPE_STRING
        );
        if (Variable::TYPE_VALUE === $propVar->type) {
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $propVar);
        } else {
            $valuePtr = $context->builder->pointerCast(
                $context->helper->loadValue($propVar),
                $context->getTypeFromString('__value__*')
            );
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
    }
}
