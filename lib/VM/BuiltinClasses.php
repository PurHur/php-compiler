<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\Builtin\DateIntervalConstruct;
use PHPCompiler\VM\Builtin\DateIntervalCreateFromDateString;
use PHPCompiler\VM\Builtin\DateIntervalFormat;
use PHPCompiler\VM\Builtin\DateTimeConstruct;
use PHPCompiler\VM\Builtin\DateTimeDiff;
use PHPCompiler\VM\Builtin\DateTimeCreateFromFormat;
use PHPCompiler\VM\Builtin\DateTimeCreateFromImmutable;
use PHPCompiler\VM\Builtin\DateTimeCreateFromInterface;
use PHPCompiler\VM\Builtin\DateTimeCreateFromTimestamp;
use PHPCompiler\VM\Builtin\DateTimeImmutableCreateFromTimestamp;
use PHPCompiler\VM\Builtin\DateTimeFormat;
use PHPCompiler\VM\Builtin\DateTimeGetLastErrors;
use PHPCompiler\VM\Builtin\DateTimeGetMicrosecond;
use PHPCompiler\VM\Builtin\DateTimeGetTimestamp;
use PHPCompiler\VM\Builtin\DateTimeImmutableConstruct;
use PHPCompiler\VM\Builtin\DateTimeImmutableCreateFromFormat;
use PHPCompiler\VM\Builtin\DateTimeImmutableCreateFromInterface;
use PHPCompiler\VM\Builtin\DateTimeImmutableCreateFromMutable;
use PHPCompiler\VM\Builtin\DateTimeModify;
use PHPCompiler\VM\Builtin\DateTimeSetMicrosecond;
use PHPCompiler\VM\Builtin\DateTimeSetTimezone;
use PHPCompiler\VM\Builtin\DateTimeZoneConstruct;
use PHPCompiler\VM\Builtin\DateTimeZoneGetLocation;
use PHPCompiler\VM\Builtin\DateTimeZoneGetName;
use PHPCompiler\VM\Builtin\DateTimeZoneGetOffset;
use PHPCompiler\VM\Builtin\ExceptionConstruct;
use PHPCompiler\VM\Builtin\ErrorExceptionConstruct;
use PHPCompiler\VM\Builtin\ErrorExceptionGetSeverity;
use PHPCompiler\VM\Builtin\ExceptionGetCode;
use PHPCompiler\VM\Builtin\ExceptionGetFile;
use PHPCompiler\VM\Builtin\ExceptionGetLine;
use PHPCompiler\VM\Builtin\ExceptionGetMessage;
use PHPCompiler\VM\Builtin\ExceptionGetPrevious;
use PHPCompiler\VM\Builtin\ExceptionGetTrace;
use PHPCompiler\VM\Builtin\ExceptionGetTraceAsString;
use PHPCompiler\VM\Builtin\ExceptionToString;
use PHPCompiler\VM\Builtin\ExceptionWakeup;
use PHPCompiler\VM\Builtin\FiberConstruct;
use PHPCompiler\VM\Builtin\FiberGetCurrent;
use PHPCompiler\VM\Builtin\FiberGetReturn;
use PHPCompiler\VM\Builtin\FiberGetTrace;
use PHPCompiler\VM\Builtin\FiberGetTraceAsString;
use PHPCompiler\VM\Builtin\FiberIsRunning;
use PHPCompiler\VM\Builtin\FiberIsStarted;
use PHPCompiler\VM\Builtin\FiberIsSuspended;
use PHPCompiler\VM\Builtin\FiberIsTerminated;
use PHPCompiler\VM\Builtin\FiberResume;
use PHPCompiler\VM\Builtin\FiberStart;
use PHPCompiler\VM\Builtin\FiberSuspend;
use PHPCompiler\VM\Builtin\FiberThrow;
use PHPCompiler\VM\Builtin\ReflectionAttributeGetArguments;
use PHPCompiler\VM\Builtin\ReflectionAttributeGetName;
use PHPCompiler\VM\Builtin\ReflectionAttributeIsRepeated;
use PHPCompiler\VM\Builtin\ReflectionAttributeNewInstance;
use PHPCompiler\VM\Builtin\ReflectionClassConstantGetDeprecatedMessage;
use PHPCompiler\VM\Builtin\ReflectionClassConstantGetDeprecatedVersion;
use PHPCompiler\VM\Builtin\ReflectionClassConstantGetType;
use PHPCompiler\VM\Builtin\ReflectionClassConstantIsDeprecated;
use PHPCompiler\VM\Builtin\ReflectionClassConstantIsEnumCase;
use PHPCompiler\VM\Builtin\ReflectionClassConstantIsFinal;
use PHPCompiler\VM\Builtin\ReflectionClassConstruct;
use PHPCompiler\VM\Builtin\ReflectionClassGetAttributes;
use PHPCompiler\VM\Builtin\ReflectionClassGetConstant;
use PHPCompiler\VM\Builtin\ReflectionClassGetConstants;
use PHPCompiler\VM\Builtin\ReflectionClassGetDefaultProperties;
use PHPCompiler\VM\Builtin\ReflectionClassGetTraitAliases;
use PHPCompiler\VM\Builtin\ReflectionClassGetTraitNames;
use PHPCompiler\VM\Builtin\ReflectionClassGetLazyInitializer;
use PHPCompiler\VM\Builtin\ReflectionClassGetLazyInitializationException;
use PHPCompiler\VM\Builtin\ReflectionClassGetLazyPropertyNames;
use PHPCompiler\VM\Builtin\ReflectionClassGetLazyProxyFactory;
use PHPCompiler\VM\Builtin\ReflectionClassGetName;
use PHPCompiler\VM\Builtin\ReflectionClassInitializeLazyObject;
use PHPCompiler\VM\Builtin\ReflectionClassGetMethod;
use PHPCompiler\VM\Builtin\ReflectionClassGetMethods;
use PHPCompiler\VM\Builtin\ReflectionClassHasConstant;
use PHPCompiler\VM\Builtin\ReflectionClassHasMethod;
use PHPCompiler\VM\Builtin\ReflectionClassHasProperty;
use PHPCompiler\VM\Builtin\ReflectionClassGetProperty;
use PHPCompiler\VM\Builtin\ReflectionClassGetProperties;
use PHPCompiler\VM\Builtin\ReflectionClassGetReadOnlyProperties;
use PHPCompiler\VM\Builtin\ReflectionClassGetStaticProperties;
use PHPCompiler\VM\Builtin\ReflectionClassGetStaticPropertyValue;
use PHPCompiler\VM\Builtin\ReflectionClassSetStaticPropertyValue;
use PHPCompiler\VM\Builtin\ReflectionClassGetReflectionConstant;
use PHPCompiler\VM\Builtin\ReflectionClassGetReflectionConstants;
use PHPCompiler\VM\Builtin\ReflectionClassGetDocComment;
use PHPCompiler\VM\Builtin\ReflectionClassGetEndLine;
use PHPCompiler\VM\Builtin\ReflectionClassGetExtensionName;
use PHPCompiler\VM\Builtin\ReflectionClassGetFileName;
use PHPCompiler\VM\Builtin\ReflectionClassGetDeprecatedMessage;
use PHPCompiler\VM\Builtin\ReflectionClassGetDeprecatedVersion;
use PHPCompiler\VM\Builtin\ReflectionClassGetStartLine;
use PHPCompiler\VM\Builtin\ReflectionClassIsDeprecated;
use PHPCompiler\VM\Builtin\ReflectionClassIsAnonymous;
use PHPCompiler\VM\Builtin\ReflectionClassIsEnum;
use PHPCompiler\VM\Builtin\ReflectionClassIsInternal;
use PHPCompiler\VM\Builtin\ReflectionClassIsStatic;
use PHPCompiler\VM\Builtin\ReflectionClassIsUserDefined;
use PHPCompiler\VM\Builtin\ReflectionClassIsUninitializedLazyObject;
use PHPCompiler\VM\Builtin\ReflectionClassMarkLazyObjectAsInitialized;
use PHPCompiler\VM\Builtin\ReflectionClassCreateLazyGhost;
use PHPCompiler\VM\Builtin\ReflectionClassCreateLazyProxy;
use PHPCompiler\VM\Builtin\ReflectionClassNewLazyGhost;
use PHPCompiler\VM\Builtin\ReflectionClassResetAsLazyGhost;
use PHPCompiler\VM\Builtin\ReflectionClassResetAsLazyObject;
use PHPCompiler\VM\Builtin\ReflectionClassResetAsLazyProxy;
use PHPCompiler\VM\Builtin\ReflectionClassNewLazyProxy;
use PHPCompiler\VM\Builtin\ReflectionConstantConstruct;
use PHPCompiler\VM\Builtin\ReflectionConstantGetAttributes;
use PHPCompiler\VM\Builtin\ReflectionConstantGetName;
use PHPCompiler\VM\Builtin\ReflectionConstantGetValue;
use PHPCompiler\VM\Builtin\ReflectionEnumBackedCaseConstruct;
use PHPCompiler\VM\Builtin\ReflectionEnumBackedCaseGetBackingValue;
use PHPCompiler\VM\Builtin\ReflectionEnumBackedCaseIsBacked;
use PHPCompiler\VM\Builtin\ReflectionEnumConstruct;
use PHPCompiler\VM\Builtin\ReflectionEnumGetCase;
use PHPCompiler\VM\Builtin\ReflectionEnumGetCases;
use PHPCompiler\VM\Builtin\ReflectionEnumGetName;
use PHPCompiler\VM\Builtin\ReflectionEnumHasCase;
use PHPCompiler\VM\Builtin\ReflectionEnumIsBacked;
use PHPCompiler\VM\Builtin\ReflectionEnumUnitCaseConstruct;
use PHPCompiler\VM\Builtin\ReflectionEnumUnitCaseGetAttributes;
use PHPCompiler\VM\Builtin\ReflectionEnumUnitCaseGetName;
use PHPCompiler\VM\Builtin\ReflectionEnumUnitCaseGetValue;
use PHPCompiler\VM\Builtin\ReflectionEnumUnitCaseIsBacked;
use PHPCompiler\VM\Builtin\ReflectionFiberGetExecutingFiber;
use PHPCompiler\VM\Builtin\ReflectionFunctionConstruct;
use PHPCompiler\VM\Builtin\ReflectionFunctionCreateFromCallable;
use PHPCompiler\VM\Builtin\ReflectionFunctionCreateFromFunction;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetClosureCalledClass;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetClosureScopeClass;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetClosureThis;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetClosureUsedVariables;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetExtensionName;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetName;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetNumberOfParameters;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetParameters;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetReturnType;
use PHPCompiler\VM\Builtin\ReflectionFunctionHasReturnType;
use PHPCompiler\VM\Builtin\ReflectionFunctionInvoke;
use PHPCompiler\VM\Builtin\ReflectionFunctionIsAnonymous;
use PHPCompiler\VM\Builtin\ReflectionFunctionIsClosure;
use PHPCompiler\VM\Builtin\ReflectionFunctionIsInternal;
use PHPCompiler\VM\Builtin\ReflectionFunctionIsUserDefined;
use PHPCompiler\VM\Builtin\ReflectionMethodConstruct;
use PHPCompiler\VM\Builtin\ReflectionMethodCreateFromClosure;
use PHPCompiler\VM\Builtin\ReflectionMethodCreateFromMethodName;
use PHPCompiler\VM\Builtin\ReflectionMethodGetAttributes;
use PHPCompiler\VM\Builtin\ReflectionMethodGetClosure;
use PHPCompiler\VM\Builtin\ReflectionMethodGetName;
use PHPCompiler\VM\Builtin\ReflectionMethodGetNumberOfParameters;
use PHPCompiler\VM\Builtin\ReflectionMethodGetParameters;
use PHPCompiler\VM\Builtin\ReflectionMethodGetPrototype;
use PHPCompiler\VM\Builtin\ReflectionMethodHasPrototype;
use PHPCompiler\VM\Builtin\ReflectionMethodGetReturnType;
use PHPCompiler\VM\Builtin\ReflectionMethodHasReturnType;
use PHPCompiler\VM\Builtin\ReflectionMethodHasTentativeReturnType;
use PHPCompiler\VM\Builtin\ReflectionMethodInvoke;
use PHPCompiler\VM\Builtin\ReflectionMethodInvokeArgs;
use PHPCompiler\VM\Builtin\ReflectionMethodGetDocComment;
use PHPCompiler\VM\Builtin\ReflectionMethodGetEndLine;
use PHPCompiler\VM\Builtin\ReflectionMethodGetExtensionName;
use PHPCompiler\VM\Builtin\ReflectionMethodGetFileName;
use PHPCompiler\VM\Builtin\ReflectionMethodGetModifiers;
use PHPCompiler\VM\Builtin\ReflectionMethodGetDeprecatedMessage;
use PHPCompiler\VM\Builtin\ReflectionMethodGetDeprecatedVersion;
use PHPCompiler\VM\Builtin\ReflectionMethodGetStartLine;
use PHPCompiler\VM\Builtin\ReflectionMethodIsDeprecated;
use PHPCompiler\VM\Builtin\ReflectionMethodIsFinal;
use PHPCompiler\VM\Builtin\ReflectionMethodIsPrivate;
use PHPCompiler\VM\Builtin\ReflectionMethodIsProtected;
use PHPCompiler\VM\Builtin\ReflectionMethodIsPublic;
use PHPCompiler\VM\Builtin\ReflectionMethodIsStatic;
use PHPCompiler\VM\Builtin\ReflectionMethodIsUserDefined;
use PHPCompiler\VM\Builtin\ReflectionNamedTypeGetName;
use PHPCompiler\VM\Builtin\ReflectionNamedTypeIsBuiltin;
use PHPCompiler\VM\Builtin\ReflectionParameterConstruct;
use PHPCompiler\VM\Builtin\ReflectionParameterGetAttributes;
use PHPCompiler\VM\Builtin\ReflectionParameterGetType;
use PHPCompiler\VM\Builtin\ReflectionParameterGetValue;
use PHPCompiler\VM\Builtin\ReflectionParameterIsSensitive;
use PHPCompiler\VM\Builtin\ReflectionPropertyConstruct;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetAsymmetricVisibility;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetAttributes;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetDeclaringClass;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetHooks;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetMangledName;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetName;
use PHPCompiler\VM\Builtin\ReflectionPropertyHasHook;
use PHPCompiler\VM\Builtin\ReflectionPropertyIsDynamic;
use PHPCompiler\VM\Builtin\ReflectionPropertyIsVirtual;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetRawValue;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetReadableType;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetSettableType;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetType;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetValue;
use PHPCompiler\VM\Builtin\ReflectionPropertySetValue;
use PHPCompiler\VM\Builtin\ReflectionPropertyAsymmetricProbe;
use PHPCompiler\VM\Builtin\ReflectionPropertyIsAbstract;
use PHPCompiler\VM\Builtin\ReflectionPropertyIsPrivate;
use PHPCompiler\VM\Builtin\ReflectionPropertyIsProtected;
use PHPCompiler\VM\Builtin\ReflectionPropertyIsPublic;
use PHPCompiler\VM\Builtin\ReflectionPropertyIsInitialized;
use PHPCompiler\VM\Builtin\ReflectionPropertyIsLazy;
use PHPCompiler\VM\Builtin\ReflectionPropertyIsPromoted;
use PHPCompiler\VM\Builtin\ReflectionPropertyIsReadOnly;
use PHPCompiler\VM\Builtin\ReflectionPropertySetRawValue;
use PHPCompiler\VM\Builtin\ReflectionPropertySetRawValueWithoutLazyInitialization;
use PHPCompiler\VM\Builtin\ReflectionPropertySkipLazyInitialization;
use PHPCompiler\VM\Builtin\ReflectionTypeAllowsNull;
use PHPCompiler\VM\Builtin\ReflectionTypeToString;
use PHPCompiler\VM\Builtin\WeakMapConstruct;
use PHPCompiler\VM\Builtin\WeakMapCount;
use PHPCompiler\VM\Builtin\WeakMapOffsetExists;
use PHPCompiler\VM\Builtin\WeakMapOffsetGet;
use PHPCompiler\VM\Builtin\WeakMapOffsetSet;
use PHPCompiler\VM\Builtin\WeakMapOffsetUnset;
use PHPCompiler\VM\Builtin\ResourceConstruct;
use PHPCompiler\VM\Builtin\WeakReferenceConstruct;
use PHPCompiler\VM\Builtin\WeakReferenceCreate;
use PHPCompiler\VM\Builtin\WeakReferenceGet;
use PHPCompiler\ext\standard\ThrowableManifest;
use PHPCompiler\VM\ExceptionSupport;
use PHPCompiler\VM\FiberSupport;

/**
 * Register VM builtin classes stdClass, WeakReference, WeakMap, Reflection*, and Throwable* (#1366, #1936, #3117, #195, #3371).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        StringableSupport::register($ctx);
        LazyGhostTraitSupport::register($ctx);
        AttributeSupport::register($ctx);
        self::registerStdClass($ctx);
        self::registerIncompleteClass($ctx);
        self::registerResource($ctx);
        self::registerCountable($ctx);
        self::registerArrayAccess($ctx);
        self::registerZendEnumInterfaces($ctx);
        self::registerSerializable($ctx);
        self::registerTraversableInterfaces($ctx);
        ZendDeclaredInterfaces::register($ctx);
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
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    /** Zend: Traversable/Iterator interfaces for instanceof and foreach parity. */
    private static function registerTraversableInterfaces(Context $ctx): void
    {
        $traversable = new ClassEntry('Traversable');
        $traversable->isInterface = true;
        $ctx->classes['traversable'] = $traversable;

        $iterator = new ClassEntry('Iterator');
        $iterator->isInterface = true;
        $iterator->interfaces = ['traversable'];
        $ctx->classes['iterator'] = $iterator;

        $iteratorAggregate = new ClassEntry('IteratorAggregate');
        $iteratorAggregate->isInterface = true;
        $iteratorAggregate->interfaces = ['traversable'];
        $ctx->classes['iteratoraggregate'] = $iteratorAggregate;
    }

    /** Zend zend_interfaces.c — Countable interface (#3364). */
    private static function registerCountable(Context $ctx): void
    {
        $entry = new ClassEntry('Countable');
        $entry->isInterface = true;
        $ctx->classes['countable'] = $entry;
    }

    /** Zend zend_interfaces.c — ArrayAccess for $obj[$key] dispatch (#3331, #5433). */
    private static function registerArrayAccess(Context $ctx): void
    {
        $entry = new ClassEntry('ArrayAccess');
        $entry->isInterface = true;
        $ctx->classes['arrayaccess'] = $entry;
    }

    /** Zend zend_interfaces.c — UnitEnum / BackedEnum for enum reflection (#6354). */
    private static function registerZendEnumInterfaces(Context $ctx): void
    {
        $unitEnum = new ClassEntry('UnitEnum');
        $unitEnum->isInterface = true;
        $ctx->classes['unitenum'] = $unitEnum;

        $backedEnum = new ClassEntry('BackedEnum');
        $backedEnum->isInterface = true;
        $backedEnum->interfaces = ['unitenum'];
        $ctx->classes['backedenum'] = $backedEnum;
    }

    /** Zend zend_interfaces.c — legacy Serializable (#3287, #6354). */
    private static function registerSerializable(Context $ctx): void
    {
        $entry = new ClassEntry('Serializable');
        $entry->isInterface = true;
        $ctx->classes['serializable'] = $entry;
    }

    private static function registerStdClass(Context $ctx): void
    {
        $entry = new ClassEntry('stdClass');
        $entry->allowsDynamicProperties = true;
        $ctx->classes['stdclass'] = $entry;
    }

    /** Zend var_unserializer.c — placeholder for missing class definitions (#6564). */
    private static function registerIncompleteClass(Context $ctx): void
    {
        $entry = new ClassEntry('__PHP_Incomplete_Class');
        $entry->allowsDynamicProperties = true;
        $ctx->classes['__php_incomplete_class'] = $entry;
    }

    /** PHP 8.4 Resource builtin — stream/dir zval wrapper (#7071, #7073). */
    private static function registerResource(Context $ctx): void
    {
        $entry = new ClassEntry('Resource');
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry->constructor = new ResourceConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $ctx->classes['resource'] = $entry;
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
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;
        $entry->methods['create'] = new WeakReferenceCreate();
        $entry->methodVisibility['create'] = $pubStatic;
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
        $entry->interfaces = ['arrayaccess', 'countable'];
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
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;

        $attr = new ClassEntry('ReflectionAttribute');
        $attr->properties[] = new ClassProperty(ReflectionSupport::PROP_ATTR_NAME, null, $strProto);
        $attr->properties[] = new ClassProperty(ReflectionSupport::PROP_ATTR_ARGS, null, $arrayProto);
        $attr->properties[] = new ClassProperty(ReflectionSupport::PROP_ATTR_IS_REPEATED, null, $boolProto);
        $attr->methods['getname'] = new ReflectionAttributeGetName();
        $attr->methodVisibility['getname'] = $pub;
        $attr->methods['getarguments'] = new ReflectionAttributeGetArguments();
        $attr->methodVisibility['getarguments'] = $pub;
        $attr->methods['isrepeated'] = new ReflectionAttributeIsRepeated();
        $attr->methodVisibility['isrepeated'] = $pub;
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
        $rparam->constructor = new ReflectionParameterConstruct();
        $rparam->methods['__construct'] = $rparam->constructor;
        $rparam->methodVisibility['__construct'] = $pub;
        $rparam->methods['getattributes'] = new ReflectionParameterGetAttributes();
        $rparam->methodVisibility['getattributes'] = $pub;
        $rparam->methods['gettype'] = new ReflectionParameterGetType();
        $rparam->methodVisibility['gettype'] = $pub;
        $rparam->methods['getvalue'] = new ReflectionParameterGetValue();
        $rparam->methodVisibility['getvalue'] = $pub;
        $rparam->methods['issensitive'] = new ReflectionParameterIsSensitive();
        $rparam->methodVisibility['issensitive'] = $pub;
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
        $rm->methods['getnumberofparameters'] = new ReflectionMethodGetNumberOfParameters();
        $rm->methodVisibility['getnumberofparameters'] = $pub;
        $rm->methods['getname'] = new ReflectionMethodGetName();
        $rm->methodVisibility['getname'] = $pub;
        $rm->methods['isdeprecated'] = new ReflectionMethodIsDeprecated();
        $rm->methodVisibility['isdeprecated'] = $pub;
        $rm->methods['getdeprecatedmessage'] = new ReflectionMethodGetDeprecatedMessage();
        $rm->methodVisibility['getdeprecatedmessage'] = $pub;
        $rm->methods['getdeprecatedversion'] = new ReflectionMethodGetDeprecatedVersion();
        $rm->methodVisibility['getdeprecatedversion'] = $pub;
        $rm->methods['hasprototype'] = new ReflectionMethodHasPrototype();
        $rm->methodVisibility['hasprototype'] = $pub;
        $rm->methods['hasreturntype'] = new ReflectionMethodHasReturnType();
        $rm->methodVisibility['hasreturntype'] = $pub;
        $rm->methods['getreturntype'] = new ReflectionMethodGetReturnType();
        $rm->methodVisibility['getreturntype'] = $pub;
        $rm->methods['hastentativereturntype'] = new ReflectionMethodHasTentativeReturnType();
        $rm->methodVisibility['hastentativereturntype'] = $pub;
        $rm->methods['getprototype'] = new ReflectionMethodGetPrototype();
        $rm->methodVisibility['getprototype'] = $pub;
        $rm->methods['invoke'] = new ReflectionMethodInvoke();
        $rm->methodVisibility['invoke'] = $pub;
        $rm->methods['invokeargs'] = new ReflectionMethodInvokeArgs();
        $rm->methodVisibility['invokeargs'] = $pub;
        $rm->methods['getclosure'] = new ReflectionMethodGetClosure();
        $rm->methodVisibility['getclosure'] = $pub;
        $rm->methods['createfromclosure'] = new ReflectionMethodCreateFromClosure();
        $rm->methodVisibility['createfromclosure'] = $pubStatic;
        $rm->methods['createfrommethodname'] = new ReflectionMethodCreateFromMethodName();
        $rm->methodVisibility['createfrommethodname'] = $pubStatic;
        $rm->methods['isstatic'] = new ReflectionMethodIsStatic();
        $rm->methodVisibility['isstatic'] = $pub;
        $rm->methods['ispublic'] = new ReflectionMethodIsPublic();
        $rm->methodVisibility['ispublic'] = $pub;
        $rm->methods['isprotected'] = new ReflectionMethodIsProtected();
        $rm->methodVisibility['isprotected'] = $pub;
        $rm->methods['isprivate'] = new ReflectionMethodIsPrivate();
        $rm->methodVisibility['isprivate'] = $pub;
        $rm->methods['isfinal'] = new ReflectionMethodIsFinal();
        $rm->methodVisibility['isfinal'] = $pub;
        $rm->methods['getmodifiers'] = new ReflectionMethodGetModifiers();
        $rm->methodVisibility['getmodifiers'] = $pub;
        foreach (
            [
                'getdoccomment' => new ReflectionMethodGetDocComment(),
                'getstartline' => new ReflectionMethodGetStartLine(),
                'getendline' => new ReflectionMethodGetEndLine(),
                'getfilename' => new ReflectionMethodGetFileName(),
                'isuserdefined' => new ReflectionMethodIsUserDefined(),
                'getextensionname' => new ReflectionMethodGetExtensionName(),
            ] as $name => $method
        ) {
            $rm->methods[$name] = $method;
            $rm->methodVisibility[$name] = $pub;
        }
        $ctx->classes[ReflectionSupport::REFLECTION_METHOD] = $rm;

        $rc = new ClassEntry('ReflectionClass');
        $rc->properties[] = new ClassProperty(ReflectionSupport::PROP_CLASS_NAME, null, $strProto);
        $rc->constructor = new ReflectionClassConstruct();
        $rc->methods['__construct'] = $rc->constructor;
        $rc->methodVisibility['__construct'] = $pub;
        $rc->methods['getattributes'] = new ReflectionClassGetAttributes();
        $rc->methodVisibility['getattributes'] = $pub;
        $rc->methods['getname'] = new ReflectionClassGetName();
        $rc->methodVisibility['getname'] = $pub;
        $rc->methods['getmethod'] = new ReflectionClassGetMethod();
        $rc->methodVisibility['getmethod'] = $pub;
        $rc->methods['hasmethod'] = new ReflectionClassHasMethod();
        $rc->methodVisibility['hasmethod'] = $pub;
        $rc->methods['getproperty'] = new ReflectionClassGetProperty();
        $rc->methodVisibility['getproperty'] = $pub;
        $rc->methods['hasproperty'] = new ReflectionClassHasProperty();
        $rc->methodVisibility['hasproperty'] = $pub;
        $rc->methods['getproperties'] = new ReflectionClassGetProperties();
        $rc->methodVisibility['getproperties'] = $pub;
        $rc->methods['getstaticproperties'] = new ReflectionClassGetStaticProperties();
        $rc->methodVisibility['getstaticproperties'] = $pub;
        $rc->methods['getstaticpropertyvalue'] = new ReflectionClassGetStaticPropertyValue();
        $rc->methodVisibility['getstaticpropertyvalue'] = $pub;
        $rc->methods['setstaticpropertyvalue'] = new ReflectionClassSetStaticPropertyValue();
        $rc->methodVisibility['setstaticpropertyvalue'] = $pub;
        $rc->methods['getreadonlyproperties'] = new ReflectionClassGetReadOnlyProperties();
        $rc->methodVisibility['getreadonlyproperties'] = $pub;
        $rc->methods['getlazypropertynames'] = new ReflectionClassGetLazyPropertyNames();
        $rc->methodVisibility['getlazypropertynames'] = $pub;
        $rc->methods['getmethods'] = new ReflectionClassGetMethods();
        $rc->methodVisibility['getmethods'] = $pub;
        $rc->methods['getreflectionconstant'] = new ReflectionClassGetReflectionConstant();
        $rc->methodVisibility['getreflectionconstant'] = $pub;
        $rc->methods['getreflectionconstants'] = new ReflectionClassGetReflectionConstants();
        $rc->methodVisibility['getreflectionconstants'] = $pub;
        $rc->methods['getconstants'] = new ReflectionClassGetConstants();
        $rc->methodVisibility['getconstants'] = $pub;
        $rc->methods['getdefaultproperties'] = new ReflectionClassGetDefaultProperties();
        $rc->methodVisibility['getdefaultproperties'] = $pub;
        $rc->methods['gettraitaliases'] = new ReflectionClassGetTraitAliases();
        $rc->methodVisibility['gettraitaliases'] = $pub;
        $rc->methods['gettraitnames'] = new ReflectionClassGetTraitNames();
        $rc->methodVisibility['gettraitnames'] = $pub;
        $rc->methods['getconstant'] = new ReflectionClassGetConstant();
        $rc->methodVisibility['getconstant'] = $pub;
        $rc->methods['hasconstant'] = new ReflectionClassHasConstant();
        $rc->methodVisibility['hasconstant'] = $pub;
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;
        $rc->methods['newlazyproxy'] = new ReflectionClassNewLazyProxy();
        $rc->methodVisibility['newlazyproxy'] = $pubStatic;
        $rc->methods['newlazyghost'] = new ReflectionClassNewLazyGhost();
        $rc->methodVisibility['newlazyghost'] = $pubStatic;
        $rc->methods['createlazyghost'] = new ReflectionClassCreateLazyGhost();
        $rc->methodVisibility['createlazyghost'] = $pubStatic;
        $rc->methods['createlazyproxy'] = new ReflectionClassCreateLazyProxy();
        $rc->methodVisibility['createlazyproxy'] = $pubStatic;
        $rc->methods['getlazyinitializer'] = new ReflectionClassGetLazyInitializer();
        $rc->methodVisibility['getlazyinitializer'] = $pub;
        $rc->methods['getlazyinitializationexception'] = new ReflectionClassGetLazyInitializationException();
        $rc->methodVisibility['getlazyinitializationexception'] = $pub;
        $rc->methods['getlazyproxyfactory'] = new ReflectionClassGetLazyProxyFactory();
        $rc->methodVisibility['getlazyproxyfactory'] = $pub;
        $rc->methods['isuninitializedlazyobject'] = new ReflectionClassIsUninitializedLazyObject();
        $rc->methodVisibility['isuninitializedlazyobject'] = $pub;
        $rc->methods['initializelazyobject'] = new ReflectionClassInitializeLazyObject();
        $rc->methodVisibility['initializelazyobject'] = $pub;
        $rc->methods['marklazyobjectasinitialized'] = new ReflectionClassMarkLazyObjectAsInitialized();
        $rc->methodVisibility['marklazyobjectasinitialized'] = $pub;
        $rc->methods['resetaslazyghost'] = new ReflectionClassResetAsLazyGhost();
        $rc->methodVisibility['resetaslazyghost'] = $pubStatic;
        $rc->methods['resetaslazyproxy'] = new ReflectionClassResetAsLazyProxy();
        $rc->methodVisibility['resetaslazyproxy'] = $pubStatic;
        $rc->methods['resetaslazyobject'] = new ReflectionClassResetAsLazyObject();
        $rc->methodVisibility['resetaslazyobject'] = $pub;
        $rc->methods['isinternal'] = new ReflectionClassIsInternal();
        $rc->methodVisibility['isinternal'] = $pub;
        $rc->methods['isenum'] = new ReflectionClassIsEnum();
        $rc->methodVisibility['isenum'] = $pub;
        $rc->methods['isanonymous'] = new ReflectionClassIsAnonymous();
        $rc->methodVisibility['isanonymous'] = $pub;
        $rc->methods['isstatic'] = new ReflectionClassIsStatic();
        $rc->methodVisibility['isstatic'] = $pub;
        $rc->methods['isdeprecated'] = new ReflectionClassIsDeprecated();
        $rc->methodVisibility['isdeprecated'] = $pub;
        $rc->methods['getdeprecatedmessage'] = new ReflectionClassGetDeprecatedMessage();
        $rc->methodVisibility['getdeprecatedmessage'] = $pub;
        $rc->methods['getdeprecatedversion'] = new ReflectionClassGetDeprecatedVersion();
        $rc->methodVisibility['getdeprecatedversion'] = $pub;
        foreach (
            [
                'getdoccomment' => new ReflectionClassGetDocComment(),
                'getstartline' => new ReflectionClassGetStartLine(),
                'getendline' => new ReflectionClassGetEndLine(),
                'getfilename' => new ReflectionClassGetFileName(),
                'isuserdefined' => new ReflectionClassIsUserDefined(),
                'getextensionname' => new ReflectionClassGetExtensionName(),
            ] as $name => $method
        ) {
            $rc->methods[$name] = $method;
            $rc->methodVisibility[$name] = $pub;
        }

        $rp = new ClassEntry('ReflectionProperty');
        $rp->properties[] = new ClassProperty(ReflectionSupport::PROP_CLASS_NAME, null, $strProto);
        $rp->properties[] = new ClassProperty(ReflectionSupport::PROP_PROPERTY_NAME, null, $strProto);
        $rp->properties[] = new ClassProperty(ReflectionSupport::PROP_DECLARING_CLASS_NAME, null, $strProto);
        \PHPCompiler\ext\standard\VmReflection::registerReflectionPropertyClassConstants($rp);
        $rp->constructor = new ReflectionPropertyConstruct();
        $rp->methods['__construct'] = $rp->constructor;
        $rp->methodVisibility['__construct'] = $pub;
        $rp->methods['getname'] = new ReflectionPropertyGetName();
        $rp->methodVisibility['getname'] = $pub;
        $rp->methods['getdeclaringclass'] = new ReflectionPropertyGetDeclaringClass();
        $rp->methodVisibility['getdeclaringclass'] = $pub;
        $rp->methods['getvalue'] = new ReflectionPropertyGetValue();
        $rp->methodVisibility['getvalue'] = $pub;
        $rp->methods['setvalue'] = new ReflectionPropertySetValue();
        $rp->methodVisibility['setvalue'] = $pub;
        $rp->methods['setrawvalue'] = new ReflectionPropertySetRawValue();
        $rp->methodVisibility['setrawvalue'] = $pub;
        $rp->methods['getrawvalue'] = new ReflectionPropertyGetRawValue();
        $rp->methodVisibility['getrawvalue'] = $pub;
        $rp->methods['getattributes'] = new ReflectionPropertyGetAttributes();
        $rp->methodVisibility['getattributes'] = $pub;
        $rp->methods['gettype'] = new ReflectionPropertyGetType();
        $rp->methodVisibility['gettype'] = $pub;
        foreach (
            [
                'ispublic' => new ReflectionPropertyIsPublic(),
                'isprivate' => new ReflectionPropertyIsPrivate(),
                'isprotected' => new ReflectionPropertyIsProtected(),
                'isabstract' => new ReflectionPropertyIsAbstract(),
                'isvirtual' => new ReflectionPropertyIsVirtual(),
                'isdynamic' => new ReflectionPropertyIsDynamic(),
                'getmangledname' => new ReflectionPropertyGetMangledName(),
                'hashook' => new ReflectionPropertyHasHook(),
                'gethooks' => new ReflectionPropertyGetHooks(),
                'isreadonly' => new ReflectionPropertyIsReadOnly(),
                'ispromoted' => new ReflectionPropertyIsPromoted(),
                'islazy' => new ReflectionPropertyIsLazy(),
                'isinitialized' => new ReflectionPropertyIsInitialized(),
                'setrawvaluewithoutlazyinitialization' => new ReflectionPropertySetRawValueWithoutLazyInitialization(),
                'skiplazyinitialization' => new ReflectionPropertySkipLazyInitialization(),
                'isprivateset' => ReflectionPropertyAsymmetricProbe::isPrivateSet(),
                'isprotectedset' => ReflectionPropertyAsymmetricProbe::isProtectedSet(),
                'ispublicset' => ReflectionPropertyAsymmetricProbe::isPublicSet(),
                'isprivateget' => ReflectionPropertyAsymmetricProbe::isPrivateGet(),
                'isprotectedget' => ReflectionPropertyAsymmetricProbe::isProtectedGet(),
                'ispublicget' => ReflectionPropertyAsymmetricProbe::isPublicGet(),
                'getasymmetricvisibility' => new ReflectionPropertyGetAsymmetricVisibility(),
                'getreadabletype' => new ReflectionPropertyGetReadableType(),
                'getsettabletype' => new ReflectionPropertyGetSettableType(),
            ] as $name => $method
        ) {
            $rp->methods[$name] = $method;
            $rp->methodVisibility[$name] = $pub;
        }
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
                'getnumberofparameters' => new ReflectionFunctionGetNumberOfParameters(),
                'getreturntype' => new ReflectionFunctionGetReturnType(),
                'hasreturntype' => new ReflectionFunctionHasReturnType(),
                'isanonymous' => new ReflectionFunctionIsAnonymous(),
                'isclosure' => new ReflectionFunctionIsClosure(),
                'isinternal' => new ReflectionFunctionIsInternal(),
                'isuserdefined' => new ReflectionFunctionIsUserDefined(),
                'getextensionname' => new ReflectionFunctionGetExtensionName(),
                'getclosurethis' => new ReflectionFunctionGetClosureThis(),
                'getclosurescopeclass' => new ReflectionFunctionGetClosureScopeClass(),
                'getclosurecalledclass' => new ReflectionFunctionGetClosureCalledClass(),
                'getclosureusedvariables' => new ReflectionFunctionGetClosureUsedVariables(),
                'invoke' => new ReflectionFunctionInvoke(),
            ] as $name => $method
        ) {
            $rf->methods[$name] = $method;
            $rf->methodVisibility[$name] = $pub;
        }
        $rf->methods['createfromcallable'] = new ReflectionFunctionCreateFromCallable();
        $rf->methodVisibility['createfromcallable'] = $pubStatic;
        $rf->methods['createfromfunction'] = new ReflectionFunctionCreateFromFunction();
        $rf->methodVisibility['createfromfunction'] = $pubStatic;
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
        $rconst->methods['getattributes'] = new ReflectionConstantGetAttributes();
        $rconst->methodVisibility['getattributes'] = $pub;
        $rconst->methods['gettype'] = new ReflectionClassConstantGetType();
        $rconst->methodVisibility['gettype'] = $pub;
        $rconst->methods['isdeprecated'] = new ReflectionClassConstantIsDeprecated();
        $rconst->methodVisibility['isdeprecated'] = $pub;
        $rconst->methods['getdeprecatedmessage'] = new ReflectionClassConstantGetDeprecatedMessage();
        $rconst->methodVisibility['getdeprecatedmessage'] = $pub;
        $rconst->methods['getdeprecatedversion'] = new ReflectionClassConstantGetDeprecatedVersion();
        $rconst->methodVisibility['getdeprecatedversion'] = $pub;
        $rconst->methods['isfinal'] = new ReflectionClassConstantIsFinal();
        $rconst->methodVisibility['isfinal'] = $pub;
        $rconst->methods['isenumcase'] = new ReflectionClassConstantIsEnumCase();
        $rconst->methodVisibility['isenumcase'] = $pub;
        $ctx->classes[ReflectionSupport::REFLECTION_CONSTANT] = $rconst;

        $rcc = new ClassEntry('ReflectionClassConstant');
        $rcc->properties[] = new ClassProperty(ReflectionSupport::PROP_CLASS_NAME, null, $strProto);
        $rcc->properties[] = new ClassProperty(ReflectionSupport::PROP_CONSTANT_NAME, null, $strProto);
        $rcc->constructor = new ReflectionConstantConstruct();
        $rcc->methods['__construct'] = $rcc->constructor;
        $rcc->methodVisibility['__construct'] = $pub;
        $rcc->methods['getname'] = new ReflectionConstantGetName();
        $rcc->methodVisibility['getname'] = $pub;
        $rcc->methods['getvalue'] = new ReflectionConstantGetValue();
        $rcc->methodVisibility['getvalue'] = $pub;
        $rcc->methods['getattributes'] = new ReflectionConstantGetAttributes();
        $rcc->methodVisibility['getattributes'] = $pub;
        $rcc->methods['gettype'] = new ReflectionClassConstantGetType();
        $rcc->methodVisibility['gettype'] = $pub;
        $rcc->methods['isdeprecated'] = new ReflectionClassConstantIsDeprecated();
        $rcc->methodVisibility['isdeprecated'] = $pub;
        $rcc->methods['getdeprecatedmessage'] = new ReflectionClassConstantGetDeprecatedMessage();
        $rcc->methodVisibility['getdeprecatedmessage'] = $pub;
        $rcc->methods['getdeprecatedversion'] = new ReflectionClassConstantGetDeprecatedVersion();
        $rcc->methodVisibility['getdeprecatedversion'] = $pub;
        $rcc->methods['isfinal'] = new ReflectionClassConstantIsFinal();
        $rcc->methodVisibility['isfinal'] = $pub;
        $rcc->methods['isenumcase'] = new ReflectionClassConstantIsEnumCase();
        $rcc->methodVisibility['isenumcase'] = $pub;
        $ctx->classes[ReflectionSupport::REFLECTION_CLASS_CONSTANT] = $rcc;

        $ctx->classes[ReflectionSupport::REFLECTION_CLASS] = $rc;

        $renum = new ClassEntry('ReflectionEnum');
        $renum->properties[] = new ClassProperty(ReflectionSupport::PROP_CLASS_NAME, null, $strProto);
        $renum->constructor = new ReflectionEnumConstruct();
        $renum->methods['__construct'] = $renum->constructor;
        $renum->methodVisibility['__construct'] = $pub;
        $renum->methods['getname'] = new ReflectionEnumGetName();
        $renum->methodVisibility['getname'] = $pub;
        $renum->methods['isbacked'] = new ReflectionEnumIsBacked();
        $renum->methodVisibility['isbacked'] = $pub;
        $renum->methods['getcases'] = new ReflectionEnumGetCases();
        $renum->methodVisibility['getcases'] = $pub;
        $renum->methods['getcase'] = new ReflectionEnumGetCase();
        $renum->methodVisibility['getcase'] = $pub;
        $renum->methods['hascase'] = new ReflectionEnumHasCase();
        $renum->methodVisibility['hascase'] = $pub;
        $renum->methods['gettraitnames'] = new ReflectionClassGetTraitNames();
        $renum->methodVisibility['gettraitnames'] = $pub;
        $ctx->classes[ReflectionSupport::REFLECTION_ENUM] = $renum;

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
        $reuc->methods['isbacked'] = new ReflectionEnumUnitCaseIsBacked();
        $reuc->methodVisibility['isbacked'] = $pub;
        $ctx->classes[ReflectionSupport::REFLECTION_ENUM_UNIT_CASE] = $reuc;

        $rebc = new ClassEntry('ReflectionEnumBackedCase');
        $rebc->parentLc = ReflectionSupport::REFLECTION_ENUM_UNIT_CASE;
        $rebc->properties[] = new ClassProperty(ReflectionSupport::PROP_CLASS_NAME, null, $strProto);
        $rebc->properties[] = new ClassProperty(ReflectionSupport::PROP_ENUM_CASE_NAME, null, $strProto);
        $rebc->constructor = new ReflectionEnumBackedCaseConstruct();
        $rebc->methods['__construct'] = $rebc->constructor;
        $rebc->methodVisibility['__construct'] = $pub;
        $rebc->methods['getbackingvalue'] = new ReflectionEnumBackedCaseGetBackingValue();
        $rebc->methodVisibility['getbackingvalue'] = $pub;
        $rebc->methods['isbacked'] = new ReflectionEnumBackedCaseIsBacked();
        $rebc->methodVisibility['isbacked'] = $pub;
        $ctx->classes[ReflectionSupport::REFLECTION_ENUM_BACKED_CASE] = $rebc;

        $reflectionType = new ClassEntry('ReflectionType');
        $reflectionType->isAbstract = true;
        $reflectionType->methods['allowsnull'] = new ReflectionTypeAllowsNull();
        $reflectionType->methodVisibility['allowsnull'] = $pub;
        $reflectionType->methods['__tostring'] = new ReflectionTypeToString();
        $reflectionType->methodVisibility['__tostring'] = $pub;
        $ctx->classes[ReflectionSupport::REFLECTION_TYPE] = $reflectionType;

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

        $objProto = new Variable(Variable::TYPE_OBJECT);
        $rfiber = new ClassEntry('ReflectionFiber');
        $rfiber->properties[] = new ClassProperty(ReflectionSupport::PROP_FIBER_TARGET, null, $objProto);
        $rfiber->methods['getexecutingfiber'] = new ReflectionFiberGetExecutingFiber();
        $rfiber->methodVisibility['getexecutingfiber'] = $pub | CfgFunc::FLAG_STATIC;
        $ctx->classes[ReflectionSupport::REFLECTION_FIBER] = $rfiber;
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
        $entry->parentLc = ReflectionSupport::REFLECTION_TYPE;
        $entry->properties[] = new ClassProperty(ReflectionSupport::PROP_TYPE_STRING, null, $strProto);
        $entry->properties[] = new ClassProperty(ReflectionSupport::PROP_TYPE_ALLOWS_NULL, null, $boolProto);
        $entry->properties[] = new ClassProperty(ReflectionSupport::PROP_TYPE_NAME, null, $strProto);
        $entry->properties[] = new ClassProperty(ReflectionSupport::PROP_TYPE_BUILTIN, null, $boolProto);
        $entry->properties[] = new ClassProperty(ReflectionSupport::PROP_TYPE_MEMBERS, null, $arrayProto);
        foreach ($extraMethods as $methodName => $method) {
            $entry->methods[$methodName] = $method;
            $entry->methodVisibility[$methodName] = $pub;
        }
        $ctx->classes[$lcKey] = $entry;
    }

    private static function registerDateTime(Context $ctx): void
    {
        DateTimeInterfaceSupport::register($ctx);

        $strProto = new Variable(Variable::TYPE_STRING);
        $intProto = new Variable(Variable::TYPE_INTEGER);
        $pub = CfgFunc::FLAG_PUBLIC;

        $tz = new ClassEntry('DateTimeZone');
        DateTimeZoneSupport::registerClassConstants($tz);
        $tz->properties[] = new ClassProperty(DateTimeSupport::TZ_NAME_PROPERTY, null, $strProto);
        $tz->constructor = new DateTimeZoneConstruct();
        $tz->methods['__construct'] = $tz->constructor;
        $tz->methodVisibility['__construct'] = $pub;
        $tz->methods['getname'] = new DateTimeZoneGetName();
        $tz->methodVisibility['getname'] = $pub;
        $tz->methods['getoffset'] = new DateTimeZoneGetOffset();
        $tz->methodVisibility['getoffset'] = $pub;
        $tz->methods['getlocation'] = new DateTimeZoneGetLocation();
        $tz->methodVisibility['getlocation'] = $pub;
        $ctx->classes[DateTimeSupport::CLASS_DATETIMEZONE] = $tz;

        $pubStatic = $pub | CfgFunc::FLAG_STATIC;
        $dateTimeMethods = [
            'format' => new DateTimeFormat(),
            'gettimestamp' => new DateTimeGetTimestamp(),
            'getmicrosecond' => new DateTimeGetMicrosecond(),
            'modify' => new DateTimeModify(),
            'diff' => new DateTimeDiff(),
            'setmicrosecond' => new DateTimeSetMicrosecond(),
            'settimezone' => new DateTimeSetTimezone(),
        ];

        $dt = new ClassEntry('DateTime');
        $dt->interfaces = [DateTimeSupport::CLASS_DATETIMEINTERFACE];
        $dt->properties[] = new ClassProperty(DateTimeSupport::TS_PROPERTY, null, $intProto);
        $dt->properties[] = new ClassProperty(DateTimeSupport::TZ_PROPERTY, null, $strProto);
        $dt->properties[] = new ClassProperty(DateTimeSupport::MICROSECOND_PROPERTY, null, $intProto);
        $dt->constructor = new DateTimeConstruct();
        $dt->methods['__construct'] = $dt->constructor;
        $dt->methodVisibility['__construct'] = $pub;
        foreach ($dateTimeMethods as $name => $method) {
            $dt->methods[$name] = $method;
            $dt->methodVisibility[$name] = $pub;
        }
        $dt->methods['createfromformat'] = new DateTimeCreateFromFormat();
        $dt->methodVisibility['createfromformat'] = $pubStatic;
        $dt->methods['createfromimmutable'] = new DateTimeCreateFromImmutable();
        $dt->methodVisibility['createfromimmutable'] = $pubStatic;
        $dt->methods['createfrominterface'] = new DateTimeCreateFromInterface();
        $dt->methodVisibility['createfrominterface'] = $pubStatic;
        $dt->methods['createfromtimestamp'] = new DateTimeCreateFromTimestamp();
        $dt->methodVisibility['createfromtimestamp'] = $pubStatic;
        $dt->methods['getlasterrors'] = new DateTimeGetLastErrors();
        $dt->methodVisibility['getlasterrors'] = $pubStatic;
        $ctx->classes[DateTimeSupport::CLASS_DATETIME] = $dt;

        $dti = new ClassEntry('DateTimeImmutable');
        $dti->interfaces = [DateTimeSupport::CLASS_DATETIMEINTERFACE];
        $dti->properties[] = new ClassProperty(DateTimeSupport::TS_PROPERTY, null, $intProto);
        $dti->properties[] = new ClassProperty(DateTimeSupport::TZ_PROPERTY, null, $strProto);
        $dti->properties[] = new ClassProperty(DateTimeSupport::MICROSECOND_PROPERTY, null, $intProto);
        $dti->constructor = new DateTimeImmutableConstruct();
        $dti->methods['__construct'] = $dti->constructor;
        $dti->methodVisibility['__construct'] = $pub;
        foreach ($dateTimeMethods as $name => $method) {
            $dti->methods[$name] = $method;
            $dti->methodVisibility[$name] = $pub;
        }
        $dti->methods['createfromformat'] = new DateTimeImmutableCreateFromFormat();
        $dti->methodVisibility['createfromformat'] = $pubStatic;
        $dti->methods['createfrommutable'] = new DateTimeImmutableCreateFromMutable();
        $dti->methodVisibility['createfrommutable'] = $pubStatic;
        $dti->methods['createfrominterface'] = new DateTimeImmutableCreateFromInterface();
        $dti->methodVisibility['createfrominterface'] = $pubStatic;
        $dti->methods['createfromtimestamp'] = new DateTimeImmutableCreateFromTimestamp();
        $dti->methodVisibility['createfromtimestamp'] = $pubStatic;
        $dti->methods['getlasterrors'] = new DateTimeGetLastErrors();
        $dti->methodVisibility['getlasterrors'] = $pubStatic;
        $ctx->classes[DateTimeSupport::CLASS_DATETIMEIMMUTABLE] = $dti;

        $floatProto = new Variable(Variable::TYPE_FLOAT);
        $boolProto = new Variable(Variable::TYPE_BOOLEAN);

        $di = new ClassEntry('DateInterval');
        foreach (['y', 'm', 'd', 'h', 'i', 's', 'invert'] as $propName) {
            $di->properties[] = new ClassProperty($propName, null, $intProto);
        }
        $di->properties[] = new ClassProperty('f', null, $floatProto);
        $di->properties[] = new ClassProperty('days', null, $boolProto);
        $di->properties[] = new ClassProperty('from_string', null, $boolProto);
        foreach ($di->properties as $prop) {
            $prop->visibility = $pub;
        }
        $di->constructor = new DateIntervalConstruct();
        $di->methods['__construct'] = $di->constructor;
        $di->methodVisibility['__construct'] = $pub;
        $di->methods['format'] = new DateIntervalFormat();
        $di->methodVisibility['format'] = $pub;
        $di->methods['createfromdatestring'] = new DateIntervalCreateFromDateString();
        $di->methodVisibility['createfromdatestring'] = $pubStatic;
        $ctx->classes[DateIntervalSupport::CLASS_DATEINTERVAL] = $di;
    }

    private static function registerExceptions(Context $ctx): void
    {
        $throwable = new ClassEntry('Throwable');
        $throwable->isInterface = true;
        $ctx->classes[ThrowableManifest::LC_THROWABLE] = $throwable;

        foreach (ThrowableManifest::registrationOrder() as $className) {
            self::registerThrowableClass(
                $ctx,
                $className,
                ThrowableManifest::lcKey($className),
                ThrowableManifest::parentLc($className)
            );
        }
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
        $prot = CfgFunc::FLAG_PROTECTED;
        $priv = CfgFunc::FLAG_PRIVATE;
        $isErrorFamily = ThrowableManifest::LC_ERROR === $lcKey
            || ThrowableManifest::isDescendantOf($lcKey, ThrowableManifest::LC_ERROR);
        $privateDeclaringLc = $isErrorFamily
            ? ThrowableManifest::LC_ERROR
            : ThrowableManifest::LC_EXCEPTION;

        $entry = new ClassEntry($name);
        if (null !== $parentLc) {
            $entry->parentLc = $parentLc;
        } else {
            $entry->interfaces = [ThrowableManifest::LC_THROWABLE];
        }
        $nullProto = new Variable(Variable::TYPE_NULL);
        $arrayProto = new Variable(Variable::TYPE_ARRAY);
        $emptyTrace = new Variable();
        $emptyTrace->newArray();
        // php-src zend_exceptions.stub.php — protected message/code/file/line; private trace/previous (+ string on Exception).
        $entry->properties[] = new ClassProperty(ExceptionSupport::PROP_MESSAGE, null, $strProto, false, $prot);
        $entry->properties[] = new ClassProperty(ExceptionSupport::PROP_CODE, null, $intProto, false, $prot);
        $entry->properties[] = new ClassProperty(ExceptionSupport::PROP_FILE, null, $strProto, false, $prot);
        $entry->properties[] = new ClassProperty(ExceptionSupport::PROP_LINE, null, $intProto, false, $prot);
        $entry->properties[] = new ClassProperty(
            ExceptionSupport::PROP_PREVIOUS,
            null,
            $nullProto,
            false,
            $priv,
            $privateDeclaringLc
        );
        $entry->properties[] = new ClassProperty(
            ExceptionSupport::PROP_TRACE,
            $emptyTrace,
            $arrayProto,
            false,
            $priv,
            $privateDeclaringLc
        );
        if (!$isErrorFamily) {
            $emptyString = new Variable(Variable::TYPE_STRING);
            $emptyString->string('');
            $entry->properties[] = new ClassProperty(
                ExceptionSupport::PROP_STRING,
                $emptyString,
                $strProto,
                false,
                $priv,
                ThrowableManifest::LC_EXCEPTION
            );
        }
        if (ThrowableManifest::LC_ERROR_EXCEPTION === $lcKey) {
            $entry->properties[] = new ClassProperty(ExceptionSupport::PROP_SEVERITY, null, $intProto);
            $entry->constructor = new ErrorExceptionConstruct();
        } else {
            $entry->constructor = new ExceptionConstruct();
        }
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['__wakeup'] = new ExceptionWakeup();
        $entry->methodVisibility['__wakeup'] = $pub;
        foreach (
            [
                'getmessage' => new ExceptionGetMessage(),
                'getcode' => new ExceptionGetCode(),
                'getfile' => new ExceptionGetFile(),
                'getline' => new ExceptionGetLine(),
                'getprevious' => new ExceptionGetPrevious(),
                'gettrace' => new ExceptionGetTrace(),
                'gettraceasstring' => new ExceptionGetTraceAsString(),
                '__tostring' => new ExceptionToString(),
            ] as $methodName => $method
        ) {
            $entry->methods[$methodName] = $method;
            $entry->methodVisibility[$methodName] = $pub;
        }
        if (ThrowableManifest::LC_ERROR_EXCEPTION === $lcKey) {
            $entry->methods['getseverity'] = new ErrorExceptionGetSeverity();
            $entry->methodVisibility['getseverity'] = $pub;
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
        // Zend zend_fibers.c: suspend/getCurrent are statically invokable inside fiber callbacks (#5485).
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;
        $entry->constructor = new FiberConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach (
            [
                'start' => new FiberStart(),
                'resume' => new FiberResume(),
                'throw' => new FiberThrow(),
                'suspend' => new FiberSuspend(),
                'getcurrent' => new FiberGetCurrent(),
                'isstarted' => new FiberIsStarted(),
                'issuspended' => new FiberIsSuspended(),
                'isrunning' => new FiberIsRunning(),
                'isterminated' => new FiberIsTerminated(),
                'getreturn' => new FiberGetReturn(),
                'gettrace' => new FiberGetTrace(),
                'gettraceasstring' => new FiberGetTraceAsString(),
            ] as $name => $method
        ) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = ('suspend' === $name || 'getcurrent' === $name)
                ? $pubStatic
                : $pub;
        }
        $ctx->classes[FiberSupport::CLASS_FIBER] = $entry;
    }
}
