<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\Builtin\DateTimeConstruct;
use PHPCompiler\VM\Builtin\DateTimeFormat;
use PHPCompiler\VM\Builtin\DateTimeGetTimestamp;
use PHPCompiler\VM\Builtin\DateTimeSetTimezone;
use PHPCompiler\VM\Builtin\DateTimeZoneConstruct;
use PHPCompiler\VM\Builtin\ExceptionConstruct;
use PHPCompiler\VM\Builtin\ExceptionGetCode;
use PHPCompiler\VM\Builtin\ExceptionGetFile;
use PHPCompiler\VM\Builtin\ExceptionGetLine;
use PHPCompiler\VM\Builtin\ExceptionGetMessage;
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
use PHPCompiler\VM\ExceptionSupport;

/**
 * Register VM builtin classes stdClass, WeakReference, WeakMap, Reflection*, and Throwable* (#1366, #1936, #3117, #195, #3371).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        self::registerStdClass($ctx);
        self::registerWeakReference($ctx);
        self::registerWeakMap($ctx);
        self::registerReflection($ctx);
        self::registerDateTime($ctx);
        self::registerExceptions($ctx);
        GeneratorState::register($ctx);
        ClosureState::register($ctx);
    }

    private static function registerStdClass(Context $ctx): void
    {
        $entry = new ClassEntry('stdClass');
        $entry->allowsDynamicProperties = true;
        $ctx->classes['stdclass'] = $entry;
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

    private static function registerDateTime(Context $ctx): void
    {
        $strProto = new Variable(Variable::TYPE_STRING);
        $intProto = new Variable(Variable::TYPE_INTEGER);
        $pub = CfgFunc::FLAG_PUBLIC;

        $tz = new ClassEntry('DateTimeZone');
        $tz->properties[] = new ClassProperty(DateTimeSupport::TZ_NAME_PROPERTY, null, $strProto);
        $tz->constructor = new DateTimeZoneConstruct();
        $tz->methods['__construct'] = $tz->constructor;
        $tz->methodVisibility['__construct'] = $pub;
        $ctx->classes[DateTimeSupport::CLASS_DATETIMEZONE] = $tz;

        $dt = new ClassEntry('DateTime');
        $dt->properties[] = new ClassProperty(DateTimeSupport::TS_PROPERTY, null, $intProto);
        $dt->properties[] = new ClassProperty(DateTimeSupport::TZ_PROPERTY, null, $strProto);
        $dt->constructor = new DateTimeConstruct();
        $dt->methods['__construct'] = $dt->constructor;
        $dt->methodVisibility['__construct'] = $pub;
        foreach (
            [
                'format' => new DateTimeFormat(),
                'gettimestamp' => new DateTimeGetTimestamp(),
                'settimezone' => new DateTimeSetTimezone(),
            ] as $name => $method
        ) {
            $dt->methods[$name] = $method;
            $dt->methodVisibility[$name] = $pub;
        }
        $ctx->classes[DateTimeSupport::CLASS_DATETIME] = $dt;
    }

    private static function registerExceptions(Context $ctx): void
    {
        $throwable = new ClassEntry('Throwable');
        $throwable->isInterface = true;
        $ctx->classes[ExceptionSupport::CLASS_THROWABLE] = $throwable;

        self::registerThrowableClass($ctx, 'Exception', ExceptionSupport::CLASS_EXCEPTION);
        self::registerThrowableClass($ctx, 'Error', ExceptionSupport::CLASS_ERROR);
        self::registerThrowableClass($ctx, 'TypeError', ExceptionSupport::CLASS_TYPE_ERROR, ExceptionSupport::CLASS_ERROR);
        self::registerThrowableClass($ctx, 'ValueError', ExceptionSupport::CLASS_VALUE_ERROR, ExceptionSupport::CLASS_ERROR);
        self::registerThrowableClass(
            $ctx,
            'ArgumentCountError',
            ExceptionSupport::CLASS_ARGUMENT_COUNT_ERROR,
            ExceptionSupport::CLASS_TYPE_ERROR
        );
        self::registerThrowableClass($ctx, 'ParseError', ExceptionSupport::CLASS_PARSE_ERROR, ExceptionSupport::CLASS_ERROR);
        self::registerThrowableClass(
            $ctx,
            'UnhandledMatchError',
            ExceptionSupport::CLASS_UNHANDLED_MATCH_ERROR,
            ExceptionSupport::CLASS_ERROR
        );
        self::registerThrowableClass(
            $ctx,
            'DivisionByZeroError',
            ExceptionSupport::CLASS_DIVISION_BY_ZERO_ERROR,
            ExceptionSupport::CLASS_ERROR
        );
        self::registerThrowableClass(
            $ctx,
            'AssertionError',
            ExceptionSupport::CLASS_ASSERTION_ERROR,
            ExceptionSupport::CLASS_ERROR
        );
    }

    private static function registerThrowableClass(
        Context $ctx,
        string $name,
        string $lcKey,
        ?string $parentLc = null
    ): void {
        $strProto = new Variable(Variable::TYPE_STRING);
        $intProto = new Variable(Variable::TYPE_INTEGER);
        $pub = CfgFunc::FLAG_PUBLIC;

        $entry = new ClassEntry($name);
        if (null !== $parentLc) {
            $entry->parentLc = $parentLc;
        } else {
            $entry->interfaces = [ExceptionSupport::CLASS_THROWABLE];
        }
        $entry->properties[] = new ClassProperty(ExceptionSupport::PROP_MESSAGE, null, $strProto);
        $entry->properties[] = new ClassProperty(ExceptionSupport::PROP_CODE, null, $intProto);
        $entry->properties[] = new ClassProperty(ExceptionSupport::PROP_FILE, null, $strProto);
        $entry->properties[] = new ClassProperty(ExceptionSupport::PROP_LINE, null, $intProto);
        $entry->constructor = new ExceptionConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach (
            [
                'getmessage' => new ExceptionGetMessage(),
                'getcode' => new ExceptionGetCode(),
                'getfile' => new ExceptionGetFile(),
                'getline' => new ExceptionGetLine(),
            ] as $methodName => $method
        ) {
            $entry->methods[$methodName] = $method;
            $entry->methodVisibility[$methodName] = $pub;
        }
        $ctx->classes[$lcKey] = $entry;
    }
}
