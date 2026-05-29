<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\Builtin\ReflectionAttributeGetName;
use PHPCompiler\VM\Builtin\ReflectionClassConstruct;
use PHPCompiler\VM\Builtin\ReflectionClassGetAttributes;
use PHPCompiler\VM\Builtin\ReflectionClassGetMethod;
use PHPCompiler\VM\Builtin\ReflectionMethodGetAttributes;
use PHPCompiler\VM\Builtin\WeakMapConstruct;
use PHPCompiler\VM\Builtin\WeakMapCount;
use PHPCompiler\VM\Builtin\WeakMapOffsetExists;
use PHPCompiler\VM\Builtin\WeakMapOffsetGet;
use PHPCompiler\VM\Builtin\WeakMapOffsetSet;
use PHPCompiler\VM\Builtin\WeakMapOffsetUnset;
use PHPCompiler\VM\Builtin\WeakReferenceConstruct;
use PHPCompiler\VM\Builtin\WeakReferenceCreate;
use PHPCompiler\VM\Builtin\WeakReferenceGet;

/**
 * Register VM builtin classes WeakReference, WeakMap, and Reflection* (#1366, #1936).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        self::registerWeakReference($ctx);
        self::registerWeakMap($ctx);
        self::registerReflection($ctx);
        GeneratorState::register($ctx);
        ClosureState::register($ctx);
    }

    private static function registerWeakReference(Context $ctx): void
    {
        $entry = new ClassEntry('WeakReference');
        $nullProto = new Variable(Variable::TYPE_NULL);
        $entry->properties[] = new ClassProperty(
            WeakRefSupport::TARGET_PROPERTY,
            null,
            $nullProto
        );
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry->methods['create'] = new WeakReferenceCreate();
        $entry->methodVisibility['create'] = $pub;
        $entry->methods['get'] = new WeakReferenceGet();
        $entry->methodVisibility['get'] = $pub;
        $entry->constructor = new WeakReferenceConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $ctx->classes['weakreference'] = $entry;
    }

    private static function registerWeakMap(Context $ctx): void
    {
        $entry = new ClassEntry('WeakMap');
        $arrayProto = new Variable(Variable::TYPE_ARRAY);
        $entry->properties[] = new ClassProperty(
            WeakRefSupport::MAP_PROPERTY,
            null,
            $arrayProto
        );
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry->constructor = new WeakMapConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach (
            [
                'offsetset' => new WeakMapOffsetSet(),
                'offsetget' => new WeakMapOffsetGet(),
                'offsetexists' => new WeakMapOffsetExists(),
                'offsetunset' => new WeakMapOffsetUnset(),
                'count' => new WeakMapCount(),
            ] as $name => $method
        ) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $pub;
        }
        $ctx->classes['weakmap'] = $entry;
    }

    private static function registerReflection(Context $ctx): void
    {
        $strProto = new Variable(Variable::TYPE_STRING);
        $pub = CfgFunc::FLAG_PUBLIC;

        $attr = new ClassEntry('ReflectionAttribute');
        $attr->properties[] = new ClassProperty(ReflectionSupport::PROP_ATTR_NAME, null, $strProto);
        $attr->methods['getname'] = new ReflectionAttributeGetName();
        $attr->methodVisibility['getname'] = $pub;
        $ctx->classes[ReflectionSupport::REFLECTION_ATTRIBUTE] = $attr;

        $rm = new ClassEntry('ReflectionMethod');
        $rm->properties[] = new ClassProperty(ReflectionSupport::PROP_CLASS_NAME, null, $strProto);
        $rm->properties[] = new ClassProperty(ReflectionSupport::PROP_METHOD_NAME, null, $strProto);
        $rm->methods['getattributes'] = new ReflectionMethodGetAttributes();
        $rm->methodVisibility['getattributes'] = $pub;
        $ctx->classes[ReflectionSupport::REFLECTION_METHOD] = $rm;

        $rc = new ClassEntry('ReflectionClass');
        $rc->properties[] = new ClassProperty(ReflectionSupport::PROP_CLASS_NAME, null, $strProto);
        $rc->constructor = new ReflectionClassConstruct();
        $rc->methods['__construct'] = $rc->constructor;
        $rc->methodVisibility['__construct'] = $pub;
        $rc->methods['getattributes'] = new ReflectionClassGetAttributes();
        $rc->methodVisibility['getattributes'] = $pub;
        $rc->methods['getmethod'] = new ReflectionClassGetMethod();
        $rc->methodVisibility['getmethod'] = $pub;
        $ctx->classes[ReflectionSupport::REFLECTION_CLASS] = $rc;
    }
}
