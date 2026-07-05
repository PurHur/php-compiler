<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * class_parents() for compiled JIT/AOT modules (#16586, php-in-PHP).
 *
 * SSOT: {@see VmReflection::resolveClassForClassImplements()}, {@see VmReflection::classParentsArray()}
 * php-src: ext/standard/class.c — PHP_FUNCTION(class_parents)
 */
final class ClassParentsJitHelper
{
    public static function parentsArgv(Variable $objectOrClass, bool $autoload): Variable
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'ClassParentsJitHelper::parentsArgv() requires an active VM context in this compiler build'
            );
        }

        $arg = $objectOrClass->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $arg->type) {
            return VmReflection::emptyArray();
        }
        if (Variable::TYPE_OBJECT === $arg->type && EnumCaseSupport::isEnumCase($arg->toObject())) {
            return VmReflection::emptyArray();
        }

        $entry = VmReflection::resolveClassForClassImplements($ctx, $objectOrClass, $autoload);
        if (null === $entry) {
            $result = new Variable();
            $result->bool(false);

            return $result;
        }

        return VmReflection::classParentsArray($entry, $ctx);
    }
}
