<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * get_parent_class() for compiled JIT/AOT modules (php-in-PHP, #1492).
 *
 * SSOT: {@see VmReflection::parentClassName()}, {@see get_parent_class_::execute()}
 * php-src: ext/standard/class.c — PHP_FUNCTION(get_parent_class)
 */
final class GetParentClassJitHelper
{
    private const OBJECT_OR_VALID_CLASS_NAME_TYPE_ERROR =
        'get_parent_class(): Argument #1 ($object_or_class) must be an object or a valid class name, %s given';

    public static function parentArgv(Variable $objectOrClass): Variable
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'GetParentClassJitHelper::parentArgv() requires an active VM context in this compiler build'
            );
        }

        $arg = $objectOrClass->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $arg->type) {
            return self::falseResult();
        }
        if (Variable::TYPE_OBJECT === $arg->type) {
            if (EnumCaseSupport::isEnumCase($arg->toObject())) {
                return self::falseResult();
            }
            $entry = $arg->toObject()->class;
        } elseif (Variable::TYPE_STRING === $arg->type) {
            $className = $arg->toString();
            $classLc = strtolower(VmReflection::normalizeGlobalIntrospectionName($className));
            if (!isset($ctx->classes[$classLc])) {
                $ctx->autoloadClass($className);
            }
            if (!isset($ctx->classes[$classLc])) {
                throw new \TypeError(\sprintf(
                    self::OBJECT_OR_VALID_CLASS_NAME_TYPE_ERROR,
                    'string'
                ));
            }
            $entry = VmReflection::resolveClassEntry($ctx, $className);
        } else {
            VmClassHas::requireObjectOrValidClassName($arg, 'get_parent_class');
            $entry = null;
        }

        if (null === $entry || $entry->isInterface || $entry->isTrait || $entry->isEnum) {
            return self::falseResult();
        }

        $parentName = VmReflection::parentClassName($entry, $ctx);
        if (null === $parentName) {
            return self::falseResult();
        }

        $result = new Variable();
        $result->string($parentName);

        return $result;
    }

    private static function falseResult(): Variable
    {
        $result = new Variable();
        $result->bool(false);

        return $result;
    }
}
