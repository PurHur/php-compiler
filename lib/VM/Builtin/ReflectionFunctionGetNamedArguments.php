<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\BuiltinParamNames;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionFunctionAbstract::getNamedArguments() — VM (#17658, ext/reflection/php_reflection.c). */
final class ReflectionFunctionGetNamedArguments extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getNamedArguments');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $receiverVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiverVar->type) {
            throw new \LogicException('ReflectionFunctionAbstract method called without object');
        }
        $receiver = $receiverVar->toObject();
        $classLc = strtolower($receiver->class->name);
        $ctx = VmReflection::requireContext($frame);
        $names = match ($classLc) {
            ReflectionSupport::REFLECTION_FUNCTION => self::namesForFunction($ctx, $receiver),
            ReflectionSupport::REFLECTION_METHOD => self::namesForMethod($ctx, $receiver),
            default => throw new \LogicException('Expected ReflectionFunction or ReflectionMethod instance'),
        };
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach ($names as $name) {
            $slot = new Variable();
            $slot->string($name);
            $ht->append($slot);
        }
        $frame->returnVar->copyFrom($result);
    }

    /** @return list<string> */
    private static function namesForFunction(Context $ctx, ObjectEntry $receiver): array
    {
        $funcName = ReflectionSupport::functionNameFromReflection($receiver);
        if (ReflectionSupport::isReflectionInternalFunction($receiver)) {
            return BuiltinParamNames::forFunction($funcName) ?? [];
        }
        $func = ReflectionSupport::resolveFunctionFromReflection($ctx, $receiver);

        return array_values($func->block->paramNames);
    }

    /** @return list<string> */
    private static function namesForMethod(Context $ctx, ObjectEntry $receiver): array
    {
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $method = ReflectionSupport::methodNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionMethod refers to unknown class in this compiler build');
        }
        $methodLc = strtolower($method);
        $params = $entry->methodParameterMetadata[$methodLc] ?? [];
        $names = [];
        foreach ($params as $meta) {
            $names[] = $meta->name;
        }

        return $names;
    }
}
