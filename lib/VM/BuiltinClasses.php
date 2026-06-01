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
use PHPCompiler\VM\Builtin\FiberConstruct;
use PHPCompiler\VM\Builtin\FiberGetCurrent;
use PHPCompiler\VM\Builtin\FiberResume;
use PHPCompiler\VM\Builtin\FiberStart;
use PHPCompiler\VM\Builtin\FiberSuspend;
use PHPCompiler\VM\Builtin\ReflectionAttributeGetArguments;
use PHPCompiler\VM\Builtin\ReflectionAttributeGetName;
use PHPCompiler\VM\Builtin\ReflectionAttributeNewInstance;
use PHPCompiler\VM\Builtin\ReflectionClassConstruct;
use PHPCompiler\VM\Builtin\ReflectionClassGetAttributes;
use PHPCompiler\VM\Builtin\ReflectionClassGetMethod;
use PHPCompiler\VM\Builtin\ReflectionClassGetMethods;
use PHPCompiler\VM\Builtin\ReflectionClassGetProperties;
use PHPCompiler\VM\Builtin\ReflectionClassNewLazyProxy;
use PHPCompiler\VM\Builtin\ReflectionConstantConstruct;
use PHPCompiler\VM\Builtin\ReflectionConstantGetName;
use PHPCompiler\VM\Builtin\ReflectionConstantGetValue;
use PHPCompiler\VM\Builtin\ReflectionEnumUnitCaseConstruct;
use PHPCompiler\VM\Builtin\ReflectionEnumUnitCaseGetAttributes;
use PHPCompiler\VM\Builtin\ReflectionEnumUnitCaseGetName;
use PHPCompiler\VM\Builtin\ReflectionEnumUnitCaseGetValue;
use PHPCompiler\VM\Builtin\ReflectionFunctionConstruct;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetName;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetParameters;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetReturnType;
use PHPCompiler\VM\Builtin\ReflectionMethodConstruct;
use PHPCompiler\VM\Builtin\ReflectionMethodGetAttributes;
use PHPCompiler\VM\Builtin\ReflectionMethodGetName;
use PHPCompiler\VM\Builtin\ReflectionMethodGetParameters;
use PHPCompiler\VM\Builtin\ReflectionNamedTypeGetName;
use PHPCompiler\VM\Builtin\ReflectionNamedTypeIsBuiltin;
use PHPCompiler\VM\Builtin\ReflectionParameterGetAttributes;
use PHPCompiler\VM\Builtin\ReflectionParameterGetType;
use PHPCompiler\VM\Builtin\ReflectionPropertyConstruct;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetName;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetValue;
use PHPCompiler\VM\Builtin\ReflectionTypeAllowsNull;
use PHPCompiler\VM\Builtin\ReflectionTypeToString;
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
use PHPCompiler\VM\FiberSupport;

/**
 * Register VM builtin classes stdClass, WeakReference, WeakMap, Reflection*, and Throwable* (#1366, #1936, #3117, #195, #3371).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        StringableSupport::register($ctx);
        self::registerStdClass($ctx);
        self::registerCountable($ctx);
        SensitiveParamSupport::register($ctx);
        self::registerWeakReference($ctx);
        self::registerWeakMap($ctx);
        self::registerReflection($ctx);
        self::registerDateTime($ctx);
        self::registerExceptions($ctx);
        self::registerJsonSerializable($ctx);
        self::registerFiber($ctx);
        GeneratorState::register($ctx);
        ClosureState::register($ctx);
    }

    /** Zend zend_interfaces.c — Countable interface (#3364). */
    private static function registerCountable(Context $ctx): void
    {
        $entry = new ClassEntry('Countable');
        $entry->isInterface = true;
        $ctx->classes['countable'] = $entry;
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
        $entry->properties[] = new ClassProperty(
            WeakRefSupport::MAP_KEYS_PROPERTY,
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
        $intProto = new Variable(Variable::TYPE_INTEGER);
        $boolProto = new Variable(Variable::TYPE_BOOLEAN);
        $arrayProto = new Variable(Variable::TYPE_ARRAY);
        $pub = CfgFunc::FLAG_PUBLIC;

        $attr = new ClassEntry('ReflectionAttribute');
        $attr->properties[] = new ClassProperty(ReflectionSupport::PROP_ATTR_NAME, null, $strProto);
        $attr->properties[] = new ClassProperty(ReflectionSupport::PROP_ATTR_ARGS, null, $arrayProto);
        $attr->methods['getname'] = new ReflectionAttributeGetName();
        $attr->methodVisibility['getname'] = $pub;
        $attr->methods['getarguments'] = new ReflectionAttributeGetArguments();
        $attr->methodVisibility['getarguments'] = $pub;
        $attr->methods['newinstance'] = new ReflectionAttributeNewInstance();
        $attr->methodVisibility['newinstance'] = $pub;
        $ctx->classes[ReflectionSupport::REFLECTION_ATTRIBUTE] = $attr;

        $rparam = new ClassEntry('ReflectionParameter');
        $rparam->properties[] = new ClassProperty(ReflectionSupport::PROP_CLASS_NAME, null, $strProto);
        $rparam->properties[] = new ClassProperty(ReflectionSupport::PROP_METHOD_NAME, null, $strProto);
        $rparam->properties[] = new ClassProperty(ReflectionSupport::PROP_FUNC_NAME, null, $strProto);
        $rparam->properties[] = new ClassProperty(ReflectionSupport::PROP_PARAM_INDEX, null, $intProto);
        $rparam->properties[] = new ClassProperty(ReflectionSupport::PROP_PARAM_NAME, null, $strProto);
        $rparam->properties[] = new ClassProperty(ReflectionSupport::PROP_PARAM_POSITION, null, $intProto);
        $rparam->methods['getattributes'] = new ReflectionParameterGetAttributes();
        $rparam->methodVisibility['getattributes'] = $pub;
        $rparam->methods['gettype'] = new ReflectionParameterGetType();
        $rparam->methodVisibility['gettype'] = $pub;
        $ctx->classes[ReflectionSupport::REFLECTION_PARAMETER] = $rparam;

        $rm = new ClassEntry('ReflectionMethod');
        $rm->properties[] = new ClassProperty(ReflectionSupport::PROP_CLASS_NAME, null, $strProto);
        $rm->properties[] = new ClassProperty(ReflectionSupport::PROP_METHOD_NAME, null, $strProto);
        $rm->constructor = new ReflectionMethodConstruct();
        $rm->methods['__construct'] = $rm->constructor;
        $rm->methodVisibility['__construct'] = $pub;
        $rm->methods['getattributes'] = new ReflectionMethodGetAttributes();
        $rm->methodVisibility['getattributes'] = $pub;
        $rm->methods['getparameters'] = new ReflectionMethodGetParameters();
        $rm->methodVisibility['getparameters'] = $pub;
        $rm->methods['getname'] = new ReflectionMethodGetName();
        $rm->methodVisibility['getname'] = $pub;
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
        $rc->methods['getproperties'] = new ReflectionClassGetProperties();
        $rc->methodVisibility['getproperties'] = $pub;
        $rc->methods['getmethods'] = new ReflectionClassGetMethods();
        $rc->methodVisibility['getmethods'] = $pub;
        $rc->methods['newlazyproxy'] = new ReflectionClassNewLazyProxy();
        $rc->methodVisibility['newlazyproxy'] = $pub;

        $rp = new ClassEntry('ReflectionProperty');
        $rp->properties[] = new ClassProperty(ReflectionSupport::PROP_CLASS_NAME, null, $strProto);
        $rp->properties[] = new ClassProperty(ReflectionSupport::PROP_PROPERTY_NAME, null, $strProto);
        $rp->constructor = new ReflectionPropertyConstruct();
        $rp->methods['__construct'] = $rp->constructor;
        $rp->methodVisibility['__construct'] = $pub;
        $rp->methods['getname'] = new ReflectionPropertyGetName();
        $rp->methodVisibility['getname'] = $pub;
        $rp->methods['getvalue'] = new ReflectionPropertyGetValue();
        $rp->methodVisibility['getvalue'] = $pub;
        $ctx->classes[ReflectionSupport::REFLECTION_PROPERTY] = $rp;

        $rf = new ClassEntry('ReflectionFunction');
        $rf->properties[] = new ClassProperty(ReflectionSupport::PROP_FUNC_NAME, null, $strProto);
        $rf->constructor = new ReflectionFunctionConstruct();
        $rf->methods['__construct'] = $rf->constructor;
        $rf->methodVisibility['__construct'] = $pub;
        foreach (
            [
                'getname' => new ReflectionFunctionGetName(),
                'getparameters' => new ReflectionFunctionGetParameters(),
                'getreturntype' => new ReflectionFunctionGetReturnType(),
            ] as $name => $method
        ) {
            $rf->methods[$name] = $method;
            $rf->methodVisibility[$name] = $pub;
        }
        $ctx->classes[ReflectionSupport::REFLECTION_FUNCTION] = $rf;

        $rconst = new ClassEntry('ReflectionConstant');
        $rconst->properties[] = new ClassProperty(ReflectionSupport::PROP_CLASS_NAME, null, $strProto);
        $rconst->properties[] = new ClassProperty(ReflectionSupport::PROP_CONSTANT_NAME, null, $strProto);
        $rconst->constructor = new ReflectionConstantConstruct();
        $rconst->methods['__construct'] = $rconst->constructor;
        $rconst->methodVisibility['__construct'] = $pub;
        $rconst->methods['getname'] = new ReflectionConstantGetName();
        $rconst->methodVisibility['getname'] = $pub;
        $rconst->methods['getvalue'] = new ReflectionConstantGetValue();
        $rconst->methodVisibility['getvalue'] = $pub;
        $ctx->classes[ReflectionSupport::REFLECTION_CONSTANT] = $rconst;

        $ctx->classes[ReflectionSupport::REFLECTION_CLASS] = $rc;

        $reuc = new ClassEntry('ReflectionEnumUnitCase');
        $reuc->properties[] = new ClassProperty(ReflectionSupport::PROP_CLASS_NAME, null, $strProto);
        $reuc->properties[] = new ClassProperty(ReflectionSupport::PROP_ENUM_CASE_NAME, null, $strProto);
        $reuc->constructor = new ReflectionEnumUnitCaseConstruct();
        $reuc->methods['__construct'] = $reuc->constructor;
        $reuc->methodVisibility['__construct'] = $pub;
        $reuc->methods['getattributes'] = new ReflectionEnumUnitCaseGetAttributes();
        $reuc->methodVisibility['getattributes'] = $pub;
        $reuc->methods['getname'] = new ReflectionEnumUnitCaseGetName();
        $reuc->methodVisibility['getname'] = $pub;
        $reuc->methods['getvalue'] = new ReflectionEnumUnitCaseGetValue();
        $reuc->methodVisibility['getvalue'] = $pub;
        $ctx->classes[ReflectionSupport::REFLECTION_ENUM_UNIT_CASE] = $reuc;

        self::registerReflectionTypeClass(
            $ctx,
            'ReflectionNamedType',
            ReflectionSupport::REFLECTION_NAMED_TYPE,
            $strProto,
            $boolProto,
            $arrayProto,
            $pub,
            [
                'getname' => new ReflectionNamedTypeGetName(),
                'isbuiltin' => new ReflectionNamedTypeIsBuiltin(),
            ]
        );
        self::registerReflectionTypeClass(
            $ctx,
            'ReflectionUnionType',
            ReflectionSupport::REFLECTION_UNION_TYPE,
            $strProto,
            $boolProto,
            $arrayProto,
            $pub,
            []
        );
        self::registerReflectionTypeClass(
            $ctx,
            'ReflectionIntersectionType',
            ReflectionSupport::REFLECTION_INTERSECTION_TYPE,
            $strProto,
            $boolProto,
            $arrayProto,
            $pub,
            []
        );
    }

    /**
     * @param array<string, VmClassMethod> $extraMethods
     */
    private static function registerReflectionTypeClass(
        Context $ctx,
        string $name,
        string $lcKey,
        Variable $strProto,
        Variable $boolProto,
        Variable $arrayProto,
        int $pub,
        array $extraMethods
    ): void {
        $entry = new ClassEntry($name);
        $entry->properties[] = new ClassProperty(ReflectionSupport::PROP_TYPE_STRING, null, $strProto);
        $entry->properties[] = new ClassProperty(ReflectionSupport::PROP_TYPE_ALLOWS_NULL, null, $boolProto);
        $entry->properties[] = new ClassProperty(ReflectionSupport::PROP_TYPE_NAME, null, $strProto);
        $entry->properties[] = new ClassProperty(ReflectionSupport::PROP_TYPE_BUILTIN, null, $boolProto);
        $entry->properties[] = new ClassProperty(ReflectionSupport::PROP_TYPE_MEMBERS, null, $arrayProto);
        $shared = [
            'allowsnull' => new ReflectionTypeAllowsNull(),
            '__tostring' => new ReflectionTypeToString(),
        ];
        foreach (array_merge($shared, $extraMethods) as $methodName => $method) {
            $entry->methods[$methodName] = $method;
            $entry->methodVisibility[$methodName] = $pub;
        }
        $ctx->classes[$lcKey] = $entry;
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
        self::registerThrowableClass(
            $ctx,
            'LogicException',
            ExceptionSupport::CLASS_LOGIC_EXCEPTION,
            ExceptionSupport::CLASS_EXCEPTION
        );
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
            'ArithmeticError',
            ExceptionSupport::CLASS_ARITHMETIC_ERROR,
            ExceptionSupport::CLASS_ERROR
        );
        self::registerThrowableClass(
            $ctx,
            'DivisionByZeroError',
            ExceptionSupport::CLASS_DIVISION_BY_ZERO_ERROR,
            ExceptionSupport::CLASS_ARITHMETIC_ERROR
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

    /** Zend JsonSerializable interface (ext/json/php_json.c, issue #3370). */
    private static function registerJsonSerializable(Context $ctx): void
    {
        $entry = new ClassEntry('JsonSerializable');
        $entry->isInterface = true;
        $ctx->classes['jsonserializable'] = $entry;
    }

    private static function registerFiber(Context $ctx): void
    {
        $entry = new ClassEntry('Fiber');
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry->constructor = new FiberConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach (
            [
                'start' => new FiberStart(),
                'resume' => new FiberResume(),
                'suspend' => new FiberSuspend(),
                'getcurrent' => new FiberGetCurrent(),
            ] as $name => $method
        ) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $pub;
        }
        $ctx->classes[FiberSupport::CLASS_FIBER] = $entry;
    }
}
