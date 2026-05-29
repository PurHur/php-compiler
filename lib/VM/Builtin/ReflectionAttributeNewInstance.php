<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\Func\PHP;
use PHPCompiler\VM\NamedArgs;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/**
 * ReflectionAttribute::newInstance() — instantiate attribute class (#3206).
 *
 * php-src: ext/reflection/php_reflection.c reflection_attribute_get_instance
 */
final class ReflectionAttributeNewInstance extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('newInstance');
    }

    public function execute(Frame $frame): void
    {
        $attr = ReflectionSupport::requireReflectionAttribute($frame, $frame->calledArgs[0]);
        $nameVar = $attr->getProperty(ReflectionSupport::PROP_ATTR_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionAttribute missing name');
        }
        $className = $nameVar->toString();
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('Reflection requires VM context');
        }
        $lc = strtolower(ltrim($className, '\\'));
        if (!isset($ctx->classes[$lc])) {
            $ctx->autoloadClass($className);
        }
        if (!isset($ctx->classes[$lc])) {
            throw new \Exception('Attribute class "'.$className.'" not found');
        }
        $classEntry = $ctx->classes[$lc];
        $object = new ObjectEntry($classEntry);
        $argSpecs = ReflectionSupport::argsFromReflectionObject($attr);
        $constructor = $classEntry->constructor;
        if ($constructor instanceof PHP) {
            $thisVar = new Variable();
            $thisVar->object($object);
            $callArgs = self::resolveConstructorArgs($constructor, $argSpecs);
            $ctx->runtime->vm->invokePhpFunction($constructor, $thisVar, ...$callArgs);
        } else {
            $object->constructed = true;
        }
        if (null !== $frame->returnVar) {
            $out = new Variable();
            $out->object($object);
            $frame->returnVar->copyFrom($out);
        }
    }

    /**
     * @param list<array{name: ?string, value: mixed}> $argSpecs
     *
     * @return list<Variable>
     */
    private static function resolveConstructorArgs(PHP $constructor, array $argSpecs): array
    {
        $paramNames = $constructor->block->paramNames;
        $variadicIndex = $constructor->block->variadicParamIndex;
        $skipThis = [] !== $paramNames && 'this' === strtolower($paramNames[0]);
        $userParamNames = $skipThis ? array_slice($paramNames, 1) : $paramNames;
        $entries = [];
        foreach ($argSpecs as $spec) {
            $var = ReflectionSupport::scalarToVariable($spec['value']);
            if (null !== $spec['name']) {
                $entries[] = ['n', $spec['name'], $var];
            } else {
                $entries[] = ['p', $var];
            }
        }

        return NamedArgs::resolve($entries, $userParamNames, $variadicIndex);
    }
}
