<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Compiler\ParameterMetadata;
use PHPCompiler\CompilerVersion;
use PHPCfg\Func as CfgFunc;
use PHPCfg\Op\Type as CfgType;
use PHPCompiler\VM\Builtin\DatePeriodConstruct;
use PHPCompiler\VM\Builtin\DatePeriodCreateFromISO8601String;
use PHPCompiler\VM\Builtin\DatePeriodGetDateInterval;
use PHPCompiler\VM\Builtin\DatePeriodGetEndDate;
use PHPCompiler\VM\Builtin\DatePeriodGetIterator;
use PHPCompiler\VM\Builtin\DatePeriodGetRecurrences;
use PHPCompiler\VM\Builtin\DatePeriodGetStartDate;
use PHPCompiler\VM\Builtin\DatePeriodSetState;
use PHPCompiler\VM\Builtin\DateIntervalConstruct;
use PHPCompiler\VM\Builtin\DateIntervalCreateFromDateString;
use PHPCompiler\VM\Builtin\DateIntervalFormat;
use PHPCompiler\VM\Builtin\DateIntervalSetState;
use PHPCompiler\VM\Builtin\DateSerializeMethods;
use PHPCompiler\VM\Builtin\DateTimeAdd;
use PHPCompiler\VM\Builtin\DateTimeConstruct;
use PHPCompiler\VM\Builtin\DateTimeDiff;
use PHPCompiler\VM\Builtin\DateTimeCreateFromFormat;
use PHPCompiler\VM\Builtin\DateTimeCreateFromImmutable;
use PHPCompiler\VM\Builtin\DateTimeCreateFromInterface;
use PHPCompiler\VM\Builtin\DateTimeCreateFromTimestamp;
use PHPCompiler\VM\Builtin\DateTimeImmutableCreateFromTimestamp;
use PHPCompiler\VM\Builtin\DateTimeFormat;
use PHPCompiler\VM\Builtin\DateTimeGetTimezone;
use PHPCompiler\VM\Builtin\DateTimeGetLastErrors;
use PHPCompiler\VM\Builtin\DateTimeGetMicrosecond;
use PHPCompiler\VM\Builtin\DateTimeGetOffset;
use PHPCompiler\VM\Builtin\DateTimeGetTimestamp;
use PHPCompiler\VM\Builtin\DateTimeImmutableConstruct;
use PHPCompiler\VM\Builtin\DateTimeImmutableCreateFromFormat;
use PHPCompiler\VM\Builtin\DateTimeImmutableCreateFromInterface;
use PHPCompiler\VM\Builtin\DateTimeImmutableCreateFromMutable;
use PHPCompiler\VM\Builtin\DateTimeModify;
use PHPCompiler\VM\Builtin\DateTimeSetDate;
use PHPCompiler\VM\Builtin\DateTimeSetISODate;
use PHPCompiler\VM\Builtin\DateTimeSetMicrosecond;
use PHPCompiler\VM\Builtin\DateTimeSetState;
use PHPCompiler\VM\Builtin\DateTimeSetTime;
use PHPCompiler\VM\Builtin\DateTimeSetTimestamp;
use PHPCompiler\VM\Builtin\DateTimeSetTimezone;
use PHPCompiler\VM\Builtin\DateTimeSub;
use PHPCompiler\VM\Builtin\DateTimeZoneConstruct;
use PHPCompiler\VM\Builtin\DateTimeZoneGetLocation;
use PHPCompiler\VM\Builtin\DateTimeZoneGetName;
use PHPCompiler\VM\Builtin\DateTimeZoneGetOffset;
use PHPCompiler\VM\Builtin\DateTimeZoneGetTransitions;
use PHPCompiler\VM\Builtin\DateTimeZoneListAbbreviations;
use PHPCompiler\VM\Builtin\DateTimeZoneListIdentifiers;
use PHPCompiler\VM\Builtin\DateTimeZoneSetState;
use PHPCompiler\VM\Builtin\ExceptionClone;
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
use PHPCompiler\VM\Builtin\FiberIsRunning;
use PHPCompiler\VM\Builtin\FiberIsStarted;
use PHPCompiler\VM\Builtin\FiberIsSuspended;
use PHPCompiler\VM\Builtin\FiberIsTerminated;
use PHPCompiler\VM\Builtin\FiberResume;
use PHPCompiler\VM\Builtin\FiberStart;
use PHPCompiler\VM\Builtin\FiberSuspend;
use PHPCompiler\VM\Builtin\FiberThrow;
use PHPCompiler\VM\Builtin\ReflectionAttributeGetArguments;
use PHPCompiler\VM\Builtin\ReflectionGetModifierNames;
use PHPCompiler\VM\Builtin\ReflectionFunctionToString;
use PHPCompiler\VM\Builtin\ReflectionMethodSetAccessible;
use PHPCompiler\VM\Builtin\ReflectionPropertySetAccessible;
use PHPCompiler\VM\Builtin\ReflectionAttributeGetName;
use PHPCompiler\VM\Builtin\ReflectionAttributeGetTarget;
use PHPCompiler\VM\Builtin\ReflectionAttributeIsRepeated;
use PHPCompiler\VM\Builtin\ReflectionAttributeNewInstance;
use PHPCompiler\VM\Builtin\ReflectionAttributeToString;
use PHPCompiler\VM\Builtin\ReflectionClassConstantGetDeprecatedMessage;
use PHPCompiler\VM\Builtin\ReflectionClassConstantGetDeprecatedVersion;
use PHPCompiler\VM\Builtin\ReflectionClassConstantGetDocComment;
use PHPCompiler\VM\Builtin\ReflectionClassConstantGetModifiers;
use PHPCompiler\VM\Builtin\ReflectionClassConstantGetType;
use PHPCompiler\VM\Builtin\ReflectionClassConstantHasType;
use PHPCompiler\VM\Builtin\ReflectionClassConstantIsDeprecated;
use PHPCompiler\VM\Builtin\ReflectionClassConstantIsEnumCase;
use PHPCompiler\VM\Builtin\ReflectionClassConstantIsFinal;
use PHPCompiler\VM\Builtin\ReflectionClassConstantIsPrivate;
use PHPCompiler\VM\Builtin\ReflectionClassConstantIsProtected;
use PHPCompiler\VM\Builtin\ReflectionClassConstantIsPublic;
use PHPCompiler\VM\Builtin\ReflectionClassConstantToString;
use PHPCompiler\VM\Builtin\ReflectionClassConstruct;
use PHPCompiler\VM\Builtin\ReflectionObjectConstruct;
use PHPCompiler\VM\Builtin\ReflectionClassGetAttributes;
use PHPCompiler\VM\Builtin\ReflectionClassGetConstructor;
use PHPCompiler\VM\Builtin\ReflectionClassGetConstant;
use PHPCompiler\VM\Builtin\ReflectionClassGetConstants;
use PHPCompiler\VM\Builtin\ReflectionClassGetDefaultProperties;
use PHPCompiler\VM\Builtin\ReflectionClassGetTraitAliases;
use PHPCompiler\VM\Builtin\ReflectionClassGetInterfaceNames;
use PHPCompiler\VM\Builtin\ReflectionClassGetInterfaces;
use PHPCompiler\VM\Builtin\ReflectionClassGetTraitNames;
use PHPCompiler\VM\Builtin\ReflectionClassGetTraits;
use PHPCompiler\VM\Builtin\ReflectionClassGetLazyInitializer;
use PHPCompiler\VM\Builtin\ReflectionClassGetName;
use PHPCompiler\VM\Builtin\ReflectionClassGetNamespaceName;
use PHPCompiler\VM\Builtin\ReflectionClassGetShortName;
use PHPCompiler\VM\Builtin\ReflectionClassInNamespace;
use PHPCompiler\VM\Builtin\ReflectionClassInitializeLazyObject;
use PHPCompiler\VM\Builtin\ReflectionClassGetMethod;
use PHPCompiler\VM\Builtin\ReflectionClassGetMethods;
use PHPCompiler\VM\Builtin\ReflectionClassHasConstant;
use PHPCompiler\VM\Builtin\ReflectionClassHasMethod;
use PHPCompiler\VM\Builtin\ReflectionClassHasProperty;
use PHPCompiler\VM\Builtin\ReflectionClassGetParentClass;
use PHPCompiler\VM\Builtin\ReflectionClassGetProperty;
use PHPCompiler\VM\Builtin\ReflectionClassGetProperties;
use PHPCompiler\VM\Builtin\ReflectionClassGetStaticProperties;
use PHPCompiler\VM\Builtin\ReflectionClassGetStaticPropertyValue;
use PHPCompiler\VM\Builtin\ReflectionClassSetStaticPropertyValue;
use PHPCompiler\VM\Builtin\ReflectionClassToString;
use PHPCompiler\VM\Builtin\ReflectionClassGetReflectionConstant;
use PHPCompiler\VM\Builtin\ReflectionClassGetReflectionConstants;
use PHPCompiler\VM\Builtin\ReflectionClassGetDocComment;
use PHPCompiler\VM\Builtin\ReflectionClassGetEndLine;
use PHPCompiler\VM\Builtin\ReflectionClassGetExtension;
use PHPCompiler\VM\Builtin\ReflectionClassGetExtensionName;
use PHPCompiler\VM\Builtin\ReflectionClassGetFileName;
use PHPCompiler\VM\Builtin\ReflectionClassGetDeprecatedMessage;
use PHPCompiler\VM\Builtin\ReflectionClassGetDeprecatedVersion;
use PHPCompiler\VM\Builtin\ReflectionClassGetStartLine;
use PHPCompiler\VM\Builtin\ReflectionClassImplementsInterface;
use PHPCompiler\VM\Builtin\ReflectionClassIsAbstract;
use PHPCompiler\VM\Builtin\ReflectionClassIsAnonymous;
use PHPCompiler\VM\Builtin\ReflectionClassIsEnum;
use PHPCompiler\VM\Builtin\ReflectionClassIsFinal;
use PHPCompiler\VM\Builtin\ReflectionClassGetModifiers;
use PHPCompiler\VM\Builtin\ReflectionClassIsInterface;
use PHPCompiler\VM\Builtin\ReflectionClassIsTrait;
use PHPCompiler\VM\Builtin\ReflectionClassIsInstance;
use PHPCompiler\VM\Builtin\ReflectionClassIsInstantiable;
use PHPCompiler\VM\Builtin\ReflectionClassIsCloneable;
use PHPCompiler\VM\Builtin\ReflectionClassIsIterateable;
use PHPCompiler\VM\Builtin\ReflectionClassIsIterable;
use PHPCompiler\VM\Builtin\ReflectionClassIsReadOnly;
use PHPCompiler\VM\Builtin\ReflectionClassIsSubclassOf;
use PHPCompiler\VM\Builtin\ReflectionClassIsInternal;
use PHPCompiler\VM\Builtin\ReflectionClassIsUserDefined;
use PHPCompiler\VM\Builtin\ReflectionClassIsUninitializedLazyObject;
use PHPCompiler\VM\Builtin\ReflectionClassMarkLazyObjectAsInitialized;
use PHPCompiler\VM\Builtin\ReflectionClassNewInstance;
use PHPCompiler\VM\Builtin\ReflectionClassNewInstanceArgs;
use PHPCompiler\VM\Builtin\ReflectionClassNewInstanceWithoutConstructor;
use PHPCompiler\VM\Builtin\ReflectionClassNewLazyGhost;
use PHPCompiler\VM\Builtin\ReflectionClassResetAsLazyGhost;
use PHPCompiler\VM\Builtin\ReflectionClassResetAsLazyProxy;
use PHPCompiler\VM\Builtin\ReflectionClassNewLazyProxy;
use PHPCompiler\VM\Builtin\ReflectionConstantConstruct;
use PHPCompiler\VM\Builtin\ReflectionConstantGetAttributes;
use PHPCompiler\VM\Builtin\ReflectionConstantGetDeclaringClass;
use PHPCompiler\VM\Builtin\ReflectionConstantGetName;
use PHPCompiler\VM\Builtin\ReflectionConstantGetNamespaceName;
use PHPCompiler\VM\Builtin\ReflectionConstantGetShortName;
use PHPCompiler\VM\Builtin\ReflectionConstantToString;
use PHPCompiler\VM\Builtin\ReflectionConstantGetFileName;
use PHPCompiler\VM\Builtin\ReflectionConstantGetExtension;
use PHPCompiler\VM\Builtin\ReflectionConstantGetExtensionName;
use PHPCompiler\VM\Builtin\ReflectionConstantInNamespace;
use PHPCompiler\VM\Builtin\ReflectionConstantGetValue;
use PHPCompiler\VM\Builtin\ReflectionConstantIsDeprecated;
use PHPCompiler\VM\Builtin\ReflectionEnumBackedCaseConstruct;
use PHPCompiler\VM\Builtin\ReflectionEnumBackedCaseGetBackingValue;
use PHPCompiler\VM\Builtin\ReflectionEnumBackedCaseIsBacked;
use PHPCompiler\VM\Builtin\ReflectionEnumConstruct;
use PHPCompiler\VM\Builtin\ReflectionEnumFromName;
use PHPCompiler\VM\Builtin\ReflectionEnumGetBackingType;
use PHPCompiler\VM\Builtin\ReflectionEnumGetCase;
use PHPCompiler\VM\Builtin\ReflectionEnumGetCases;
use PHPCompiler\VM\Builtin\ReflectionEnumGetName;
use PHPCompiler\VM\Builtin\ReflectionEnumHasCase;
use PHPCompiler\VM\Builtin\ReflectionEnumIsBacked;
use PHPCompiler\VM\Builtin\ReflectionEnumUnitCaseConstruct;
use PHPCompiler\VM\Builtin\ReflectionEnumUnitCaseGetAttributes;
use PHPCompiler\VM\Builtin\ReflectionEnumUnitCaseGetDeclaringClass;
use PHPCompiler\VM\Builtin\ReflectionEnumUnitCaseGetDocComment;
use PHPCompiler\VM\Builtin\ReflectionEnumUnitCaseGetEnum;
use PHPCompiler\VM\Builtin\ReflectionEnumUnitCaseGetName;
use PHPCompiler\VM\Builtin\ReflectionEnumUnitCaseGetValue;
use PHPCompiler\VM\Builtin\ReflectionEnumUnitCaseIsBacked;
use PHPCompiler\VM\Builtin\ReflectionEnumUnitCaseIsDeprecated;
use PHPCompiler\VM\Builtin\ReflectionEnumUnitCaseToString;
use PHPCompiler\VM\Builtin\ReflectionExtensionConstruct;
use PHPCompiler\VM\Builtin\ReflectionExtensionGetClasses;
use PHPCompiler\VM\Builtin\ReflectionExtensionGetClassNames;
use PHPCompiler\VM\Builtin\ReflectionExtensionGetConstants;
use PHPCompiler\VM\Builtin\ReflectionExtensionGetDependencies;
use PHPCompiler\VM\Builtin\ReflectionExtensionGetFunctions;
use PHPCompiler\VM\Builtin\ReflectionExtensionGetINIEntries;
use PHPCompiler\VM\Builtin\ReflectionExtensionGetName;
use PHPCompiler\VM\Builtin\ReflectionExtensionGetVersion;
use PHPCompiler\VM\Builtin\ReflectionExtensionInfo;
use PHPCompiler\VM\Builtin\ReflectionExtensionIsPersistent;
use PHPCompiler\VM\Builtin\ReflectionExtensionIsTemporary;
use PHPCompiler\VM\Builtin\ReflectionExtensionToString;
use PHPCompiler\VM\Builtin\ReflectionZendExtensionConstruct;
use PHPCompiler\VM\Builtin\ReflectionZendExtensionGetAuthor;
use PHPCompiler\VM\Builtin\ReflectionZendExtensionGetCopyright;
use PHPCompiler\VM\Builtin\ReflectionZendExtensionGetName;
use PHPCompiler\VM\Builtin\ReflectionZendExtensionGetURL;
use PHPCompiler\VM\Builtin\ReflectionZendExtensionGetVersion;
use PHPCompiler\VM\Builtin\ReflectionZendExtensionToString;
use PHPCompiler\VM\Builtin\ReflectionFiberConstruct;
use PHPCompiler\VM\Builtin\ReflectionFiberGetCallable;
use PHPCompiler\VM\Builtin\ReflectionFiberGetExecutingFile;
use PHPCompiler\VM\Builtin\ReflectionFiberGetExecutingLine;
use PHPCompiler\VM\Builtin\ReflectionFiberGetFiber;
use PHPCompiler\VM\Builtin\ReflectionFiberGetTrace;
use PHPCompiler\VM\Builtin\ReflectionGeneratorConstruct;
use PHPCompiler\VM\Builtin\ReflectionGeneratorGetExecutingFile;
use PHPCompiler\VM\Builtin\ReflectionGeneratorGetExecutingGenerator;
use PHPCompiler\VM\Builtin\ReflectionGeneratorGetExecutingLine;
use PHPCompiler\VM\Builtin\ReflectionGeneratorGetFunction;
use PHPCompiler\VM\Builtin\ReflectionGeneratorGetThis;
use PHPCompiler\VM\Builtin\ReflectionGeneratorGetTrace;
use PHPCompiler\VM\Builtin\ReflectionGeneratorIsClosed;
use PHPCompiler\VM\Builtin\ReflectionReferenceFromArrayElement;
use PHPCompiler\VM\Builtin\ReflectionReferenceGetId;
use PHPCompiler\VM\Builtin\ReflectionReferenceConstruct;
use PHPCompiler\VM\Builtin\ReflectionFunctionConstruct;
use PHPCompiler\VM\Builtin\ReflectionFunctionCreateFromCallable;
use PHPCompiler\VM\Builtin\ReflectionFunctionCreateFromFunction;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetClosure;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetClosureCalledClass;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetClosureScopeClass;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetClosureThis;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetClosureUsedVariables;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetAttributes;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetExtension;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetExtensionName;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetFileName;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetDocComment;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetEndLine;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetStartLine;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetNamespaceName;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetShortName;
use PHPCompiler\VM\Builtin\ReflectionFunctionInNamespace;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetName;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetNamedArguments;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetNumberOfParameters;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetNumberOfRequiredParameters;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetParameters;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetReturnType;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetStaticVariables;
use PHPCompiler\VM\Builtin\ReflectionFunctionGetTentativeReturnType;
use PHPCompiler\VM\Builtin\ReflectionFunctionHasReturnType;
use PHPCompiler\VM\Builtin\ReflectionFunctionHasTentativeReturnType;
use PHPCompiler\VM\Builtin\ReflectionFunctionInvoke;
use PHPCompiler\VM\Builtin\ReflectionFunctionInvokeArgs;
use PHPCompiler\VM\Builtin\ReflectionFunctionIsAnonymous;
use PHPCompiler\VM\Builtin\ReflectionFunctionIsClosure;
use PHPCompiler\VM\Builtin\ReflectionFunctionIsDisabled;
use PHPCompiler\VM\Builtin\ReflectionFunctionIsGenerator;
use PHPCompiler\VM\Builtin\ReflectionFunctionIsInternal;
use PHPCompiler\VM\Builtin\ReflectionFunctionIsDeprecated;
use PHPCompiler\VM\Builtin\ReflectionFunctionIsStatic;
use PHPCompiler\VM\Builtin\ReflectionFunctionIsUserDefined;
use PHPCompiler\VM\Builtin\ReflectionFunctionIsVariadic;
use PHPCompiler\VM\Builtin\ReflectionFunctionReturnsReference;
use PHPCompiler\VM\Builtin\ReflectionMethodConstruct;
use PHPCompiler\VM\Builtin\ReflectionMethodCreateFromClosure;
use PHPCompiler\VM\Builtin\ReflectionMethodCreateFromMethodName;
use PHPCompiler\VM\Builtin\ReflectionMethodGetAttributes;
use PHPCompiler\VM\Builtin\ReflectionMethodGetClosure;
use PHPCompiler\VM\Builtin\ReflectionMethodGetClosureCalledClass;
use PHPCompiler\VM\Builtin\ReflectionMethodGetClosureScopeClass;
use PHPCompiler\VM\Builtin\ReflectionMethodGetClosureUsedVariables;
use PHPCompiler\VM\Builtin\ReflectionMethodGetClosureThis;
use PHPCompiler\VM\Builtin\ReflectionMethodGetName;
use PHPCompiler\VM\Builtin\ReflectionMethodGetNamespaceName;
use PHPCompiler\VM\Builtin\ReflectionMethodGetNumberOfParameters;
use PHPCompiler\VM\Builtin\ReflectionMethodGetShortName;
use PHPCompiler\VM\Builtin\ReflectionMethodInNamespace;
use PHPCompiler\VM\Builtin\ReflectionMethodGetNumberOfRequiredParameters;
use PHPCompiler\VM\Builtin\ReflectionMethodGetParameters;
use PHPCompiler\VM\Builtin\ReflectionMethodGetPrototype;
use PHPCompiler\VM\Builtin\ReflectionMethodHasPrototype;
use PHPCompiler\VM\Builtin\ReflectionMethodGetReturnType;
use PHPCompiler\VM\Builtin\ReflectionMethodGetStaticVariables;
use PHPCompiler\VM\Builtin\ReflectionMethodHasReturnType;
use PHPCompiler\VM\Builtin\ReflectionMethodGetTentativeReturnType;
use PHPCompiler\VM\Builtin\ReflectionMethodHasTentativeReturnType;
use PHPCompiler\VM\Builtin\ReflectionMethodIsAbstract;
use PHPCompiler\VM\Builtin\ReflectionMethodIsClosure;
use PHPCompiler\VM\Builtin\ReflectionMethodIsConstructor;
use PHPCompiler\VM\Builtin\ReflectionMethodIsDestructor;
use PHPCompiler\VM\Builtin\ReflectionMethodIsInternal;
use PHPCompiler\VM\Builtin\ReflectionMethodIsVariadic;
use PHPCompiler\VM\Builtin\ReflectionMethodReturnsReference;
use PHPCompiler\VM\Builtin\ReflectionMethodInvoke;
use PHPCompiler\VM\Builtin\ReflectionMethodInvokeArgs;
use PHPCompiler\VM\Builtin\ReflectionMethodGetDeclaringClass;
use PHPCompiler\VM\Builtin\ReflectionMethodGetDocComment;
use PHPCompiler\VM\Builtin\ReflectionMethodGetEndLine;
use PHPCompiler\VM\Builtin\ReflectionMethodGetExtension;
use PHPCompiler\VM\Builtin\ReflectionMethodGetExtensionName;
use PHPCompiler\VM\Builtin\ReflectionMethodGetFileName;
use PHPCompiler\VM\Builtin\ReflectionMethodGetModifiers;
use PHPCompiler\VM\Builtin\ReflectionMethodGetDeprecatedMessage;
use PHPCompiler\VM\Builtin\ReflectionMethodGetDeprecatedVersion;
use PHPCompiler\VM\Builtin\ReflectionMethodGetStartLine;
use PHPCompiler\VM\Builtin\ReflectionMethodIsDeprecated;
use PHPCompiler\VM\Builtin\ReflectionMethodIsFinal;
use PHPCompiler\VM\Builtin\ReflectionMethodIsGenerator;
use PHPCompiler\VM\Builtin\ReflectionMethodIsPrivate;
use PHPCompiler\VM\Builtin\ReflectionMethodIsProtected;
use PHPCompiler\VM\Builtin\ReflectionMethodIsPublic;
use PHPCompiler\VM\Builtin\ReflectionMethodIsStatic;
use PHPCompiler\VM\Builtin\ReflectionMethodIsUserDefined;
use PHPCompiler\VM\Builtin\ReflectionMethodToString;
use PHPCompiler\VM\Builtin\ReflectionCompositeTypeGetTypes;
use PHPCompiler\VM\Builtin\ReflectionNamedTypeGetName;
use PHPCompiler\VM\Builtin\ReflectionNamedTypeIsBuiltin;
use PHPCompiler\VM\Builtin\ReflectionParameterAllowsNull;
use PHPCompiler\VM\Builtin\ReflectionParameterCanBePassedByValue;
use PHPCompiler\VM\Builtin\ReflectionParameterConstruct;
use PHPCompiler\VM\Builtin\ReflectionParameterGetAttributes;
use PHPCompiler\VM\Builtin\ReflectionParameterGetClass;
use PHPCompiler\VM\Builtin\ReflectionParameterGetDeclaringClass;
use PHPCompiler\VM\Builtin\ReflectionParameterGetDeclaringFunction;
use PHPCompiler\VM\Builtin\ReflectionParameterGetDefaultValue;
use PHPCompiler\VM\Builtin\ReflectionParameterGetDefaultValueConstantName;
use PHPCompiler\VM\Builtin\ReflectionParameterGetName;
use PHPCompiler\VM\Builtin\ReflectionParameterGetPosition;
use PHPCompiler\VM\Builtin\ReflectionParameterGetType;
use PHPCompiler\VM\Builtin\ReflectionParameterHasType;
use PHPCompiler\VM\Builtin\ReflectionParameterIsArray;
use PHPCompiler\VM\Builtin\ReflectionParameterIsCallable;
use PHPCompiler\VM\Builtin\ReflectionParameterIsDefaultValueAvailable;
use PHPCompiler\VM\Builtin\ReflectionParameterIsDefaultValueConstant;
use PHPCompiler\VM\Builtin\ReflectionParameterIsDeprecated;
use PHPCompiler\VM\Builtin\ReflectionParameterIsOptional;
use PHPCompiler\VM\Builtin\ReflectionParameterIsPassedByReference;
use PHPCompiler\VM\Builtin\ReflectionParameterIsPromoted;
use PHPCompiler\VM\Builtin\ReflectionParameterIsSensitive;
use PHPCompiler\VM\Builtin\ReflectionParameterIsSensitiveParameter;
use PHPCompiler\VM\Builtin\ReflectionParameterIsVariadic;
use PHPCompiler\VM\Builtin\ReflectionParameterToString;
use PHPCompiler\VM\Builtin\ReflectionPropertyConstruct;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetAsymmetricVisibility;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetAttributes;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetDeclaringClass;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetDefaultValue;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetDocComment;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetHook;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetHooks;
use PHPCompiler\VM\Builtin\ReflectionPropertyHasDefaultValue;
use PHPCompiler\VM\Builtin\ReflectionPropertyHasType;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetMangledName;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetName;
use PHPCompiler\VM\Builtin\ReflectionPropertyHasHook;
use PHPCompiler\VM\Builtin\ReflectionPropertyHasHooks;
use PHPCompiler\VM\Builtin\ReflectionPropertyIsDeprecated;
use PHPCompiler\VM\Builtin\ReflectionPropertyIsDynamic;
use PHPCompiler\VM\Builtin\ReflectionPropertyIsFinal;
use PHPCompiler\VM\Builtin\ReflectionPropertyIsVirtual;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetRawValue;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetReadableType;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetSettableType;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetType;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetValue;
use PHPCompiler\VM\Builtin\ReflectionPropertySetValue;
use PHPCompiler\VM\Builtin\ReflectionPropertyAsymmetricProbe;
use PHPCompiler\VM\Builtin\ReflectionPropertyAccessProbe;
use PHPCompiler\VM\Builtin\ReflectionPropertyGetModifiers;
use PHPCompiler\VM\Builtin\ReflectionPropertyIsAbstract;
use PHPCompiler\VM\Builtin\ReflectionPropertyIsDefault;
use PHPCompiler\VM\Builtin\ReflectionPropertyIsPrivate;
use PHPCompiler\VM\Builtin\ReflectionPropertyIsProtected;
use PHPCompiler\VM\Builtin\ReflectionPropertyIsPublic;
use PHPCompiler\VM\Builtin\ReflectionPropertyIsInitialized;
use PHPCompiler\VM\Builtin\ReflectionPropertyIsLazy;
use PHPCompiler\VM\Builtin\ReflectionPropertyIsPromoted;
use PHPCompiler\VM\Builtin\ReflectionPropertyIsReadOnly;
use PHPCompiler\VM\Builtin\ReflectionPropertyIsStatic;
use PHPCompiler\VM\Builtin\ReflectionPropertySetRawValue;
use PHPCompiler\VM\Builtin\ReflectionPropertySetRawValueWithoutLazyInitialization;
use PHPCompiler\VM\Builtin\ReflectionPropertySkipLazyInitialization;
use PHPCompiler\VM\Builtin\ReflectionPropertyToString;
use PHPCompiler\VM\Builtin\ReflectionTypeAllowsNull;
use PHPCompiler\VM\Builtin\ReflectionTypeToString;
use PHPCompiler\VM\Builtin\WeakMapConstruct;
use PHPCompiler\VM\Builtin\WeakMapCount;
use PHPCompiler\VM\Builtin\WeakMapGetIterator;
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
        self::registerBuiltinInterfaceMethods($iterator, ['current', 'key', 'next', 'rewind', 'valid']);
        $ctx->classes['iterator'] = $iterator;

        $iteratorAggregate = new ClassEntry('IteratorAggregate');
        $iteratorAggregate->isInterface = true;
        $iteratorAggregate->interfaces = ['traversable'];
        self::registerBuiltinInterfaceMethods($iteratorAggregate, ['getIterator']);
        $ctx->classes['iteratoraggregate'] = $iteratorAggregate;
    }

    /**
     * Zend interface method tables for get_class_methods() / method_exists() (#11786, zend_API.c).
     *
     * @param list<string> $methods
     */
    public static function registerBuiltinInterfaceMethods(ClassEntry $iface, array $methods): void
    {
        $declLc = strtolower($iface->name);
        foreach ($methods as $name) {
            $lc = strtolower($name);
            $iface->abstractMethods[$lc] = true;
            $iface->methodNames[$lc] = $name;
            $iface->methodVisibility[$lc] = CfgFunc::FLAG_PUBLIC;
            $iface->methodDeclaringClassLc[$lc] = $declLc;
        }
    }

    /** Zend zend_interfaces.c — Countable interface (#3364). */
    private static function registerCountable(Context $ctx): void
    {
        $entry = new ClassEntry('Countable');
        $entry->isInterface = true;
        self::registerBuiltinInterfaceMethods($entry, ['count']);
        $ctx->classes['countable'] = $entry;
    }

    /** Zend zend_interfaces.c / zend_interfaces.stub.php — ArrayAccess (#3331, #5433, #25425). */
    private static function registerArrayAccess(Context $ctx): void
    {
        $entry = new ClassEntry('ArrayAccess');
        $entry->isInterface = true;
        self::registerBuiltinInterfaceMethods($entry, ['offsetExists', 'offsetGet', 'offsetSet', 'offsetUnset']);
        // Stub arginfo for LSP/Reflection — without params, implements fatals (#25425).
        // mixed dump types parse as untyped for variance (TypeSig::fromDumpTypeString).
        // Omit methodReturnDeclaredTypes so untyped implementors stay Zend-compatible.
        $offset = new ParameterMetadata('offset', [], false, false, false, false, 'mixed', null);
        $value = new ParameterMetadata('value', [], false, false, false, false, 'mixed', null);
        $entry->methodParameterMetadata['offsetexists'] = [$offset];
        $entry->methodParameterMetadata['offsetget'] = [$offset];
        $entry->methodParameterMetadata['offsetset'] = [$offset, $value];
        $entry->methodParameterMetadata['offsetunset'] = [$offset];
        $ctx->classes['arrayaccess'] = $entry;
    }

    /** Zend zend_interfaces.c — UnitEnum / BackedEnum for enum reflection (#6354). */
    private static function registerZendEnumInterfaces(Context $ctx): void
    {
        $unitEnum = new ClassEntry('UnitEnum');
        $unitEnum->isInterface = true;
        self::registerBuiltinInterfaceMethods($unitEnum, ['cases']);
        $ctx->classes['unitenum'] = $unitEnum;

        $backedEnum = new ClassEntry('BackedEnum');
        $backedEnum->isInterface = true;
        $backedEnum->interfaces = ['unitenum'];
        self::registerBuiltinInterfaceMethods($backedEnum, ['tryFrom', 'from']);
        $ctx->classes['backedenum'] = $backedEnum;
    }

    /** Zend zend_interfaces.c / zend_interfaces.stub.php — legacy Serializable (#3287, #6354, #25406). */
    private static function registerSerializable(Context $ctx): void
    {
        $entry = new ClassEntry('Serializable');
        $entry->isInterface = true;
        self::registerBuiltinInterfaceMethods($entry, ['serialize', 'unserialize']);
        // Stub arginfo for LSP after #25384 — serialize() has no params; unserialize(string $data)
        // has no declared return (tentative in php-src). Untyped $data implementers stay valid.
        $entry->methodParameterMetadata['unserialize'] = [
            new ParameterMetadata(
                'data',
                [],
                false,
                false,
                false,
                false,
                'string',
                null,
            ),
        ];
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
        // php-src Zend/zend_weakrefs.stub.php — final class WeakReference (#28390).
        $entry->isFinal = true;
        // zend_weakrefs.c — clone_obj unset; WeakReference is uncloneable (#25962).
        $entry->denyClone = true;
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
        // php-src Zend/zend_weakrefs.stub.php — final class WeakMap (#28390).
        $entry->isFinal = true;
        // ZEND_ACC_NO_DYNAMIC_PROPERTIES (zend_weakrefs.c; #26371).
        $entry->noDynamicProperties = true;
        // Zend/zend_weakrefs.c — ArrayAccess + Countable + IteratorAggregate (#22267).
        $entry->interfaces = ['arrayaccess', 'countable', 'iteratoraggregate'];
        $arrayProto = new Variable(Variable::TYPE_ARRAY);
        // Engine storage only — Zend WeakMap has no PHP-visible props; DEBUG/VAR_EXPORT
        // use zend_weakmap_get_properties_for (Zend/zend_weakrefs.c; #24522).
        foreach (
            [
                WeakRefSupport::MAP_PROPERTY,
                WeakRefSupport::MAP_KEYS_PROPERTY,
            ] as $mapPropName
        ) {
            $mapProp = new ClassProperty($mapPropName, null, $arrayProto);
            $mapProp->phpInvisible = true;
            $entry->properties[] = $mapProp;
        }
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
                'getiterator' => new WeakMapGetIterator(),
            ] as $name => $method
        ) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $pub;
            if ('getiterator' === $name) {
                $entry->methodNames[$name] = 'getIterator';
            }
        }
        $ctx->classes['weakmap'] = $entry;
    }

    private static function registerReflection(Context $ctx): void
    {
        $strProto = new Variable(Variable::TYPE_STRING);
        $intProto = new Variable(Variable::TYPE_INTEGER);
        $boolProto = new Variable(Variable::TYPE_BOOLEAN);
        $boolFalseDefault = new Variable(Variable::TYPE_BOOLEAN);
        $boolFalseDefault->bool(false);
        $arrayProto = new Variable(Variable::TYPE_ARRAY);
        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;

        // php-src class Reflection — static getModifierNames() (#22127)
        $reflection = new ClassEntry('Reflection');
        $reflection->methods['getmodifiernames'] = new ReflectionGetModifierNames();
        $reflection->methodVisibility['getmodifiernames'] = $pubStatic;
        $ctx->classes[ReflectionSupport::REFLECTION] = $reflection;

        $attr = new ClassEntry('ReflectionAttribute');
        \PHPCompiler\ext\standard\VmReflection::registerReflectionAttributeClassConstants($attr);
        // Engine storage only — php-src ReflectionAttribute has no PHP-visible props (#22513).
        foreach (
            [
                new ClassProperty(ReflectionSupport::PROP_ATTR_NAME, null, $strProto),
                new ClassProperty(ReflectionSupport::PROP_ATTR_ARGS, null, $arrayProto),
                new ClassProperty(ReflectionSupport::PROP_ATTR_IS_REPEATED, null, $boolProto),
                new ClassProperty(ReflectionSupport::PROP_ATTR_TARGET, null, $intProto),
                new ClassProperty(ReflectionSupport::PROP_ATTR_VALIDATION_ERROR, null, $strProto),
            ] as $attrProp
        ) {
            $attrProp->phpInvisible = true;
            $attr->properties[] = $attrProp;
        }
        $attr->methods['getname'] = new ReflectionAttributeGetName();
        $attr->methodVisibility['getname'] = $pub;
        $attr->methods['getarguments'] = new ReflectionAttributeGetArguments();
        $attr->methodVisibility['getarguments'] = $pub;
        $attr->methods['isrepeated'] = new ReflectionAttributeIsRepeated();
        $attr->methodVisibility['isrepeated'] = $pub;
        $attr->methods['gettarget'] = new ReflectionAttributeGetTarget();
        $attr->methodVisibility['gettarget'] = $pub;
        $attr->methods['newinstance'] = new ReflectionAttributeNewInstance();
        $attr->methodVisibility['newinstance'] = $pub;
        $attr->methods['__tostring'] = new ReflectionAttributeToString();
        $attr->methodVisibility['__tostring'] = $pub;
        $ctx->classes[ReflectionSupport::REFLECTION_ATTRIBUTE] = $attr;

        $rext = new ClassEntry('ReflectionExtension');
        $rext->properties[] = new ClassProperty(ReflectionSupport::PROP_EXTENSION_NAME, null, $strProto);
        $rext->constructor = new ReflectionExtensionConstruct();
        $rext->methods['__construct'] = $rext->constructor;
        $rext->methodVisibility['__construct'] = $pub;
        $rext->methods['getname'] = new ReflectionExtensionGetName();
        $rext->methodVisibility['getname'] = $pub;
        $rext->methods['getversion'] = new ReflectionExtensionGetVersion();
        $rext->methodVisibility['getversion'] = $pub;
        $rext->methods['getfunctions'] = new ReflectionExtensionGetFunctions();
        $rext->methodVisibility['getfunctions'] = $pub;
        $rext->methods['getclasses'] = new ReflectionExtensionGetClasses();
        $rext->methodVisibility['getclasses'] = $pub;
        $rext->methods['getconstants'] = new ReflectionExtensionGetConstants();
        $rext->methodVisibility['getconstants'] = $pub;
        $rext->methods['getclassnames'] = new ReflectionExtensionGetClassNames();
        $rext->methodVisibility['getclassnames'] = $pub;
        $rext->methods['getdependencies'] = new ReflectionExtensionGetDependencies();
        $rext->methodVisibility['getdependencies'] = $pub;
        $rext->methods['getinientries'] = new ReflectionExtensionGetINIEntries();
        $rext->methodVisibility['getinientries'] = $pub;
        $rext->methods['ispersistent'] = new ReflectionExtensionIsPersistent();
        $rext->methodVisibility['ispersistent'] = $pub;
        $rext->methods['istemporary'] = new ReflectionExtensionIsTemporary();
        $rext->methodVisibility['istemporary'] = $pub;
        $rext->methods['info'] = new ReflectionExtensionInfo();
        $rext->methodVisibility['info'] = $pub;
        $rext->methods['__tostring'] = new ReflectionExtensionToString();
        $rext->methodVisibility['__tostring'] = $pub;
        $ctx->classes[ReflectionSupport::REFLECTION_EXTENSION] = $rext;

        $rzext = new ClassEntry('ReflectionZendExtension');
        $rzext->properties[] = new ClassProperty(ReflectionSupport::PROP_ZEND_EXTENSION_NAME, null, $strProto);
        $rzext->constructor = new ReflectionZendExtensionConstruct();
        $rzext->methods['__construct'] = $rzext->constructor;
        $rzext->methodVisibility['__construct'] = $pub;
        $rzext->methods['getname'] = new ReflectionZendExtensionGetName();
        $rzext->methodVisibility['getname'] = $pub;
        $rzext->methods['getversion'] = new ReflectionZendExtensionGetVersion();
        $rzext->methodVisibility['getversion'] = $pub;
        $rzext->methods['getauthor'] = new ReflectionZendExtensionGetAuthor();
        $rzext->methodVisibility['getauthor'] = $pub;
        $rzext->methods['geturl'] = new ReflectionZendExtensionGetURL();
        $rzext->methodVisibility['geturl'] = $pub;
        $rzext->methods['getcopyright'] = new ReflectionZendExtensionGetCopyright();
        $rzext->methodVisibility['getcopyright'] = $pub;
        $rzext->methods['__tostring'] = new ReflectionZendExtensionToString();
        $rzext->methodVisibility['__tostring'] = $pub;
        $ctx->classes[ReflectionSupport::REFLECTION_ZEND_EXTENSION] = $rzext;

        $rparam = new ClassEntry('ReflectionParameter');
        // Zend public dump surface is only `$name` (parameter name) (#22528, re-#22488).
        $rparamName = new ClassProperty(ReflectionSupport::PROP_PARAM_NAME, null, $strProto);
        $rparam->properties[] = $rparamName;
        $rparamClass = new ClassProperty(ReflectionSupport::PROP_PARAM_CLASS, null, $strProto);
        $rparamClass->phpInvisible = true;
        $rparam->properties[] = $rparamClass;
        $rparamMethod = new ClassProperty(ReflectionSupport::PROP_METHOD_NAME, null, $strProto);
        $rparamMethod->phpInvisible = true;
        $rparam->properties[] = $rparamMethod;
        $rparamFunc = new ClassProperty(ReflectionSupport::PROP_FUNC_NAME, null, $strProto);
        $rparamFunc->phpInvisible = true;
        $rparam->properties[] = $rparamFunc;
        $rparamIndex = new ClassProperty(ReflectionSupport::PROP_PARAM_INDEX, null, $intProto);
        $rparamIndex->phpInvisible = true;
        $rparam->properties[] = $rparamIndex;
        $rparamPos = new ClassProperty(ReflectionSupport::PROP_PARAM_POSITION, null, $intProto);
        $rparamPos->phpInvisible = true;
        $rparam->properties[] = $rparamPos;
        $rparam->constructor = new ReflectionParameterConstruct();
        $rparam->methods['__construct'] = $rparam->constructor;
        $rparam->methodVisibility['__construct'] = $pub;
        $rparam->methods['getattributes'] = new ReflectionParameterGetAttributes();
        $rparam->methodVisibility['getattributes'] = $pub;
        $rparam->methods['getclass'] = new ReflectionParameterGetClass();
        $rparam->methodVisibility['getclass'] = $pub;
        $rparam->methods['getdeclaringclass'] = new ReflectionParameterGetDeclaringClass();
        $rparam->methodVisibility['getdeclaringclass'] = $pub;
        $rparam->methods['getdeclaringfunction'] = new ReflectionParameterGetDeclaringFunction();
        $rparam->methodVisibility['getdeclaringfunction'] = $pub;
        $rparam->methods['getname'] = new ReflectionParameterGetName();
        $rparam->methodVisibility['getname'] = $pub;
        $rparam->methods['getposition'] = new ReflectionParameterGetPosition();
        $rparam->methodVisibility['getposition'] = $pub;
        $rparam->methods['gettype'] = new ReflectionParameterGetType();
        $rparam->methodVisibility['gettype'] = $pub;
        $rparam->methods['hastype'] = new ReflectionParameterHasType();
        $rparam->methodVisibility['hastype'] = $pub;
        // php-src ReflectionParameter has no getValue/isNamed — phantoms vs Zend (#25057, re-#5127/#18073).
        $rparam->methods['getdefaultvalue'] = new ReflectionParameterGetDefaultValue();
        $rparam->methodVisibility['getdefaultvalue'] = $pub;
        $rparam->methods['getdefaultvalueconstantname'] = new ReflectionParameterGetDefaultValueConstantName();
        $rparam->methodVisibility['getdefaultvalueconstantname'] = $pub;
        $rparam->methods['isarray'] = new ReflectionParameterIsArray();
        $rparam->methodVisibility['isarray'] = $pub;
        $rparam->methods['iscallable'] = new ReflectionParameterIsCallable();
        $rparam->methodVisibility['iscallable'] = $pub;
        $rparam->methods['isdefaultvalueavailable'] = new ReflectionParameterIsDefaultValueAvailable();
        $rparam->methodVisibility['isdefaultvalueavailable'] = $pub;
        $rparam->methods['isdefaultvalueconstant'] = new ReflectionParameterIsDefaultValueConstant();
        $rparam->methodVisibility['isdefaultvalueconstant'] = $pub;
        $rparam->methods['isoptional'] = new ReflectionParameterIsOptional();
        $rparam->methodVisibility['isoptional'] = $pub;
        $rparam->methods['allowsnull'] = new ReflectionParameterAllowsNull();
        $rparam->methodVisibility['allowsnull'] = $pub;
        $rparam->methods['canbepassedbyvalue'] = new ReflectionParameterCanBePassedByValue();
        $rparam->methodVisibility['canbepassedbyvalue'] = $pub;
        $rparam->methods['ispassedbyreference'] = new ReflectionParameterIsPassedByReference();
        $rparam->methodVisibility['ispassedbyreference'] = $pub;
        $rparam->methods['isvariadic'] = new ReflectionParameterIsVariadic();
        $rparam->methodVisibility['isvariadic'] = $pub;
        // isSensitive / isSensitiveParameter: phantom vs php-src (#28528) — never register.
        // Builtin classes kept for spine; #[\SensitiveParameter] redaction is SensitiveParamSupport.
        if (CompilerVersion::supportsReflectionParameterIsSensitiveParameter()) {
            $rparam->methods['issensitive'] = new ReflectionParameterIsSensitive();
            $rparam->methodVisibility['issensitive'] = $pub;
            $rparam->methods['issensitiveparameter'] = new ReflectionParameterIsSensitiveParameter();
            $rparam->methodVisibility['issensitiveparameter'] = $pub;
        }
        // ReflectionParameter::isDeprecated: phantom vs php-src (#28529) — never register.
        // Builtin class kept for spine; Function/ClassConstant isDeprecated stay gated separately.
        if (CompilerVersion::supportsReflectionPropertyParameterIsDeprecated()) {
            $rparam->methods['isdeprecated'] = new ReflectionParameterIsDeprecated();
            $rparam->methodVisibility['isdeprecated'] = $pub;
        }
        $rparam->methods['ispromoted'] = new ReflectionParameterIsPromoted();
        $rparam->methodVisibility['ispromoted'] = $pub;
        $rparam->methods['__tostring'] = new ReflectionParameterToString();
        $rparam->methodVisibility['__tostring'] = $pub;
        $ctx->classes[ReflectionSupport::REFLECTION_PARAMETER] = $rparam;

        $rfa = new ClassEntry('ReflectionFunctionAbstract');
        $rfa->isAbstract = true;
        $rfa->methods['getattributes'] = new ReflectionFunctionGetAttributes();
        $rfa->methodVisibility['getattributes'] = $pub;
        $ctx->classes[ReflectionSupport::REFLECTION_FUNCTION_ABSTRACT] = $rfa;

        $rm = new ClassEntry('ReflectionMethod');
        $rm->parentLc = ReflectionSupport::REFLECTION_FUNCTION_ABSTRACT;
        $rm->properties[] = new ClassProperty(ReflectionSupport::PROP_REFLECTION_METHOD_CLASS, null, $strProto);
        $rm->properties[] = new ClassProperty(ReflectionSupport::PROP_REFLECTION_METHOD_FUNC, null, $strProto);
        // ref->accessible is C-only in php-src — not a PHP property (#22514).
        $rmAccess = new ClassProperty(ReflectionSupport::PROP_ACCESSIBLE, $boolFalseDefault, $boolProto);
        $rmAccess->phpInvisible = true;
        $rm->properties[] = $rmAccess;
        $rm->constructor = new ReflectionMethodConstruct();
        $rm->methods['__construct'] = $rm->constructor;
        $rm->methodVisibility['__construct'] = $pub;
        $rm->methods['getattributes'] = new ReflectionMethodGetAttributes();
        $rm->methodVisibility['getattributes'] = $pub;
        $rm->methods['getparameters'] = new ReflectionMethodGetParameters();
        $rm->methodVisibility['getparameters'] = $pub;
        $rm->methods['getstaticvariables'] = new ReflectionMethodGetStaticVariables();
        $rm->methodVisibility['getstaticvariables'] = $pub;
        $rm->methods['getnumberofparameters'] = new ReflectionMethodGetNumberOfParameters();
        $rm->methodVisibility['getnumberofparameters'] = $pub;
        $rm->methods['getnumberofrequiredparameters'] = new ReflectionMethodGetNumberOfRequiredParameters();
        $rm->methodVisibility['getnumberofrequiredparameters'] = $pub;
        $rm->methods['getname'] = new ReflectionMethodGetName();
        $rm->methodVisibility['getname'] = $pub;
        $rm->methods['getnamespacename'] = new ReflectionMethodGetNamespaceName();
        $rm->methodVisibility['getnamespacename'] = $pub;
        $rm->methods['getshortname'] = new ReflectionMethodGetShortName();
        $rm->methodVisibility['getshortname'] = $pub;
        $rm->methods['innamespace'] = new ReflectionMethodInNamespace();
        $rm->methodVisibility['innamespace'] = $pub;
        $rm->methods['getdeclaringclass'] = new ReflectionMethodGetDeclaringClass();
        $rm->methodVisibility['getdeclaringclass'] = $pub;
        $rm->methods['isdeprecated'] = new ReflectionMethodIsDeprecated();
        $rm->methodVisibility['isdeprecated'] = $pub;
        // getDeprecatedMessage/Version are PHP 8.4+ (#[\Deprecated] metadata) — absent on 8.2 (#25058).
        if (CompilerVersion::supportsReflectionClassPhp84Apis()) {
            $rm->methods['getdeprecatedmessage'] = new ReflectionMethodGetDeprecatedMessage();
            $rm->methodVisibility['getdeprecatedmessage'] = $pub;
            $rm->methods['getdeprecatedversion'] = new ReflectionMethodGetDeprecatedVersion();
            $rm->methodVisibility['getdeprecatedversion'] = $pub;
        }
        $rm->methods['hasprototype'] = new ReflectionMethodHasPrototype();
        $rm->methodVisibility['hasprototype'] = $pub;
        $rm->methods['hasreturntype'] = new ReflectionMethodHasReturnType();
        $rm->methodVisibility['hasreturntype'] = $pub;
        $rm->methods['getreturntype'] = new ReflectionMethodGetReturnType();
        $rm->methodVisibility['getreturntype'] = $pub;
        $rm->methods['hastentativereturntype'] = new ReflectionMethodHasTentativeReturnType();
        $rm->methodVisibility['hastentativereturntype'] = $pub;
        $rm->methods['gettentativereturntype'] = new ReflectionMethodGetTentativeReturnType();
        $rm->methodVisibility['gettentativereturntype'] = $pub;
        $rm->methods['isconstructor'] = new ReflectionMethodIsConstructor();
        $rm->methodVisibility['isconstructor'] = $pub;
        $rm->methods['isdestructor'] = new ReflectionMethodIsDestructor();
        $rm->methodVisibility['isdestructor'] = $pub;
        $rm->methods['isabstract'] = new ReflectionMethodIsAbstract();
        $rm->methodVisibility['isabstract'] = $pub;
        $rm->methods['isinternal'] = new ReflectionMethodIsInternal();
        $rm->methodVisibility['isinternal'] = $pub;
        $rm->methods['isvariadic'] = new ReflectionMethodIsVariadic();
        $rm->methodVisibility['isvariadic'] = $pub;
        $rm->methods['returnsreference'] = new ReflectionMethodReturnsReference();
        $rm->methodVisibility['returnsreference'] = $pub;
        $rm->methods['getprototype'] = new ReflectionMethodGetPrototype();
        $rm->methodVisibility['getprototype'] = $pub;
        $rm->methods['invoke'] = new ReflectionMethodInvoke();
        $rm->methodVisibility['invoke'] = $pub;
        $rm->methods['invokeargs'] = new ReflectionMethodInvokeArgs();
        $rm->methodVisibility['invokeargs'] = $pub;
        $rm->methods['getclosure'] = new ReflectionMethodGetClosure();
        $rm->methodVisibility['getclosure'] = $pub;
        $rm->methods['getclosurescopeclass'] = new ReflectionMethodGetClosureScopeClass();
        $rm->methodVisibility['getclosurescopeclass'] = $pub;
        $rm->methods['getclosurecalledclass'] = new ReflectionMethodGetClosureCalledClass();
        $rm->methodVisibility['getclosurecalledclass'] = $pub;
        $rm->methods['getclosurethis'] = new ReflectionMethodGetClosureThis();
        $rm->methodVisibility['getclosurethis'] = $pub;
        $rm->methods['getclosureusedvariables'] = new ReflectionMethodGetClosureUsedVariables();
        $rm->methodVisibility['getclosureusedvariables'] = $pub;
        if (CompilerVersion::supportsReflectionCreateFromFactories()) {
            $rm->methods['createfromclosure'] = new ReflectionMethodCreateFromClosure();
            $rm->methodVisibility['createfromclosure'] = $pubStatic;
            $rm->methods['createfrommethodname'] = new ReflectionMethodCreateFromMethodName();
            $rm->methodVisibility['createfrommethodname'] = $pubStatic;
        }
        $rm->methods['isstatic'] = new ReflectionMethodIsStatic();
        $rm->methodVisibility['isstatic'] = $pub;
        $rm->methods['ispublic'] = new ReflectionMethodIsPublic();
        $rm->methodVisibility['ispublic'] = $pub;
        $rm->methods['isprotected'] = new ReflectionMethodIsProtected();
        $rm->methodVisibility['isprotected'] = $pub;
        $rm->methods['isprivate'] = new ReflectionMethodIsPrivate();
        $rm->methodVisibility['isprivate'] = $pub;
        // setAccessible exists on Method/Property (deprecated no-op since 8.1 for
        // invoke/getValue); isAccessible is C-internal only in php-src (#22512).
        $rm->methods['setaccessible'] = new ReflectionMethodSetAccessible();
        $rm->methodVisibility['setaccessible'] = $pub;
        $rm->methods['isfinal'] = new ReflectionMethodIsFinal();
        $rm->methodVisibility['isfinal'] = $pub;
        $rm->methods['isgenerator'] = new ReflectionMethodIsGenerator();
        $rm->methodVisibility['isgenerator'] = $pub;
        $rm->methods['isclosure'] = new ReflectionMethodIsClosure();
        $rm->methodVisibility['isclosure'] = $pub;
        $rm->methods['__tostring'] = new ReflectionMethodToString();
        $rm->methodVisibility['__tostring'] = $pub;
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
                'getextension' => new ReflectionMethodGetExtension(),
            ] as $name => $method
        ) {
            $rm->methods[$name] = $method;
            $rm->methodVisibility[$name] = $pub;
        }
        if (CompilerVersion::supportsReflectionFunctionGetNamedArguments()) {
            $rm->methods['getnamedarguments'] = new ReflectionFunctionGetNamedArguments();
            $rm->methodVisibility['getnamedarguments'] = $pub;
        }
        \PHPCompiler\ext\standard\VmReflection::registerReflectionMethodClassConstants($rm);
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
        $rc->methods['getshortname'] = new ReflectionClassGetShortName();
        $rc->methodVisibility['getshortname'] = $pub;
        $rc->methods['getnamespacename'] = new ReflectionClassGetNamespaceName();
        $rc->methodVisibility['getnamespacename'] = $pub;
        $rc->methods['innamespace'] = new ReflectionClassInNamespace();
        $rc->methodVisibility['innamespace'] = $pub;
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
        // getReadOnlyProperties / getLazyPropertyNames are phantoms vs php-src (#28516).
        // Readonly: getProperties() + ReflectionProperty::isReadOnly(); lazy names: not in stub.
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
        $rc->methods['gettraits'] = new ReflectionClassGetTraits();
        $rc->methodVisibility['gettraits'] = $pub;
        $rc->methods['getinterfacenames'] = new ReflectionClassGetInterfaceNames();
        $rc->methodVisibility['getinterfacenames'] = $pub;
        $rc->methods['getinterfaces'] = new ReflectionClassGetInterfaces();
        $rc->methodVisibility['getinterfaces'] = $pub;
        $rc->methods['getparentclass'] = new ReflectionClassGetParentClass();
        $rc->methodVisibility['getparentclass'] = $pub;
        $rc->methods['getconstructor'] = new ReflectionClassGetConstructor();
        $rc->methodVisibility['getconstructor'] = $pub;
        $rc->methods['issubclassof'] = new ReflectionClassIsSubclassOf();
        $rc->methodVisibility['issubclassof'] = $pub;
        $rc->methods['implementsinterface'] = new ReflectionClassImplementsInterface();
        $rc->methodVisibility['implementsinterface'] = $pub;
        $rc->methods['isinstance'] = new ReflectionClassIsInstance();
        $rc->methodVisibility['isinstance'] = $pub;
        $rc->methods['isinstantiable'] = new ReflectionClassIsInstantiable();
        $rc->methodVisibility['isinstantiable'] = $pub;
        $rc->methods['iscloneable'] = new ReflectionClassIsCloneable();
        $rc->methodVisibility['iscloneable'] = $pub;
        $rc->methods['newinstance'] = new ReflectionClassNewInstance();
        $rc->methodVisibility['newinstance'] = $pub;
        $rc->methods['newinstanceargs'] = new ReflectionClassNewInstanceArgs();
        $rc->methodVisibility['newinstanceargs'] = $pub;
        $rc->methods['newinstancewithoutconstructor'] = new ReflectionClassNewInstanceWithoutConstructor();
        $rc->methodVisibility['newinstancewithoutconstructor'] = $pub;
        $rc->methods['isabstract'] = new ReflectionClassIsAbstract();
        $rc->methodVisibility['isabstract'] = $pub;
        $rc->methods['isfinal'] = new ReflectionClassIsFinal();
        $rc->methodVisibility['isfinal'] = $pub;
        $rc->methods['isinterface'] = new ReflectionClassIsInterface();
        $rc->methodVisibility['isinterface'] = $pub;
        $rc->methods['istrait'] = new ReflectionClassIsTrait();
        $rc->methodVisibility['istrait'] = $pub;
        $rc->methods['getmodifiers'] = new ReflectionClassGetModifiers();
        $rc->methodVisibility['getmodifiers'] = $pub;
        $rc->methods['isiterateable'] = new ReflectionClassIsIterateable();
        // php-src: isIterable() — same semantics as isIterateable() (#22117).
        $rc->methods['isiterable'] = new ReflectionClassIsIterable();
        $rc->methodVisibility['isiterateable'] = $pub;
        $rc->methods['getconstant'] = new ReflectionClassGetConstant();
        $rc->methodVisibility['getconstant'] = $pub;
        $rc->methods['hasconstant'] = new ReflectionClassHasConstant();
        $rc->methodVisibility['hasconstant'] = $pub;
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;
        if (CompilerVersion::supportsLazyObjectFactories()) {
            // php-src ReflectionClass lazy surface only (ext/reflection/php_reflection.stub.php) —
            // newLazyGhost/newLazyProxy + reset/initialize/mark/isUninitialized/getLazyInitializer.
            // Phantoms withheld (#28516): createLazyGhost/Proxy, resetAsLazyObject,
            // getLazyInitializationException, getLazyProxyFactory.
            $rc->methods['newlazyproxy'] = new ReflectionClassNewLazyProxy();
            $rc->methodVisibility['newlazyproxy'] = $pub;
            $rc->methods['newlazyghost'] = new ReflectionClassNewLazyGhost();
            $rc->methodVisibility['newlazyghost'] = $pub;
            $rc->methods['getlazyinitializer'] = new ReflectionClassGetLazyInitializer();
            $rc->methodVisibility['getlazyinitializer'] = $pub;
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
            // php-src stub return types — absent from php-types InternalArgInfo (#27741).
            $objectRet = ReflectionTypeSupport::cfgTypeFromLabel('object');
            $voidRet = ReflectionTypeSupport::cfgTypeFromLabel('void');
            if (null !== $objectRet) {
                $rc->methodReturnDeclaredTypes['newlazyghost'] = $objectRet;
                $rc->methodReturnDeclaredTypes['newlazyproxy'] = $objectRet;
            }
            if (null !== $voidRet) {
                $rc->methodReturnDeclaredTypes['resetaslazyghost'] = $voidRet;
                $rc->methodReturnDeclaredTypes['resetaslazyproxy'] = $voidRet;
            }
        }
        $rc->methods['isinternal'] = new ReflectionClassIsInternal();
        $rc->methodVisibility['isinternal'] = $pub;
        $rc->methods['isenum'] = new ReflectionClassIsEnum();
        $rc->methodVisibility['isenum'] = $pub;
        $rc->methods['isreadonly'] = new ReflectionClassIsReadOnly();
        $rc->methodVisibility['isreadonly'] = $pub;
        $rc->methods['isanonymous'] = new ReflectionClassIsAnonymous();
        $rc->methodVisibility['isanonymous'] = $pub;
        if (CompilerVersion::supportsReflectionClassPhp84Apis()) {
            // ReflectionClass::isStatic() is NOT in php-src — static-class RFC unmerged (#28518).
            // Leave ReflectionProperty/Method/Function::isStatic alone. Builtin class file kept for spine.
            $rc->methods['getdeprecatedmessage'] = new ReflectionClassGetDeprecatedMessage();
            $rc->methodVisibility['getdeprecatedmessage'] = $pub;
            $rc->methods['getdeprecatedversion'] = new ReflectionClassGetDeprecatedVersion();
            $rc->methodVisibility['getdeprecatedversion'] = $pub;
        }
        foreach (
            [
                'getdoccomment' => new ReflectionClassGetDocComment(),
                'getstartline' => new ReflectionClassGetStartLine(),
                'getendline' => new ReflectionClassGetEndLine(),
                'getfilename' => new ReflectionClassGetFileName(),
                'isuserdefined' => new ReflectionClassIsUserDefined(),
                'getextensionname' => new ReflectionClassGetExtensionName(),
                'getextension' => new ReflectionClassGetExtension(),
            ] as $name => $method
        ) {
            $rc->methods[$name] = $method;
            $rc->methodVisibility[$name] = $pub;
        }
        $rc->methods['__tostring'] = new ReflectionClassToString();
        $rc->methodVisibility['__tostring'] = $pub;

        $rp = new ClassEntry('ReflectionProperty');
        // Zend dump/public surface: $name = property, $class = declaring class (#22504).
        // Property registration order matches Zend print_r/var_dump (name then class).
        $rp->properties[] = new ClassProperty(ReflectionSupport::PROP_PROPERTY_NAME, null, $strProto);
        $rp->properties[] = new ClassProperty(ReflectionSupport::PROP_DECLARING_CLASS_NAME, null, $strProto);
        $rpIsDynamic = new ClassProperty(ReflectionSupport::PROP_IS_DYNAMIC, null, $boolProto);
        $rpIsDynamic->phpInvisible = true;
        $rp->properties[] = $rpIsDynamic;
        // ref->accessible is C-only in php-src — not a PHP property (#22514).
        $rpAccess = new ClassProperty(ReflectionSupport::PROP_ACCESSIBLE, $boolFalseDefault, $boolProto);
        $rpAccess->phpInvisible = true;
        $rp->properties[] = $rpAccess;
        \PHPCompiler\ext\standard\VmReflection::registerReflectionPropertyClassConstants($rp);
        $rp->constructor = new ReflectionPropertyConstruct();
        $rp->methods['__construct'] = $rp->constructor;
        $rp->methodVisibility['__construct'] = $pub;
        $rp->methods['getname'] = new ReflectionPropertyGetName();
        $rp->methodVisibility['getname'] = $pub;
        $rp->methods['getdeclaringclass'] = new ReflectionPropertyGetDeclaringClass();
        $rp->methodVisibility['getdeclaringclass'] = $pub;
        $rp->methods['getdefaultvalue'] = new ReflectionPropertyGetDefaultValue();
        $rp->methodVisibility['getdefaultvalue'] = $pub;
        $rp->methods['getdoccomment'] = new ReflectionPropertyGetDocComment();
        $rp->methodVisibility['getdoccomment'] = $pub;
        $rp->methods['getvalue'] = new ReflectionPropertyGetValue();
        $rp->methodVisibility['getvalue'] = $pub;
        $rp->methods['setvalue'] = new ReflectionPropertySetValue();
        $rp->methodVisibility['setvalue'] = $pub;
        $rp->methods['setaccessible'] = new ReflectionPropertySetAccessible();
        $rp->methodVisibility['setaccessible'] = $pub;
        // getRawValue/setRawValue are PHP 8.4+ only (#22601; re-#6451).
        // isDefaultValueAvailable is never a ReflectionProperty method (#22601; re-#11442/#7295).
        // getMangledName is PHP 8.5+ only (#27592).
        if (CompilerVersion::supportsReflectionPropertyPhp84RawValueApis()) {
            $rp->methods['setrawvalue'] = new ReflectionPropertySetRawValue();
            $rp->methodVisibility['setrawvalue'] = $pub;
            $rp->methods['getrawvalue'] = new ReflectionPropertyGetRawValue();
            $rp->methodVisibility['getrawvalue'] = $pub;
            // Explicit `mixed` is Literal — CfgType\Mixed_ means undeclared (#22064 / #27599).
            $rp->methodReturnDeclaredTypes['getrawvalue'] = new CfgType\Literal('mixed');
            $voidRet = ReflectionTypeSupport::cfgTypeFromLabel('void');
            if (null !== $voidRet) {
                $rp->methodReturnDeclaredTypes['setrawvalue'] = $voidRet;
            }
        }
        if (CompilerVersion::supportsReflectionPropertyGetMangledName()) {
            $rp->methods['getmangledname'] = new ReflectionPropertyGetMangledName();
            $rp->methodVisibility['getmangledname'] = $pub;
            $stringRet = ReflectionTypeSupport::cfgTypeFromLabel('string');
            if (null !== $stringRet) {
                $rp->methodReturnDeclaredTypes['getmangledname'] = $stringRet;
            }
        }
        $rp->methods['getattributes'] = new ReflectionPropertyGetAttributes();
        $rp->methodVisibility['getattributes'] = $pub;
        $rp->methods['gettype'] = new ReflectionPropertyGetType();
        $rp->methodVisibility['gettype'] = $pub;
        $rp->methods['hastype'] = new ReflectionPropertyHasType();
        $rp->methodVisibility['hastype'] = $pub;
        foreach (
            [
                'ispublic' => new ReflectionPropertyIsPublic(),
                'isprivate' => new ReflectionPropertyIsPrivate(),
                'isprotected' => new ReflectionPropertyIsProtected(),
                'isstatic' => new ReflectionPropertyIsStatic(),
                'isdefault' => new ReflectionPropertyIsDefault(),
                'getmodifiers' => new ReflectionPropertyGetModifiers(),
                'isreadonly' => new ReflectionPropertyIsReadOnly(),
                'ispromoted' => new ReflectionPropertyIsPromoted(),
                'isinitialized' => new ReflectionPropertyIsInitialized(),
                'hasdefaultvalue' => new ReflectionPropertyHasDefaultValue(),
            ] as $name => $method
        ) {
            $rp->methods[$name] = $method;
            $rp->methodVisibility[$name] = $pub;
        }
        // ReflectionProperty::getReadableType: phantom vs php-src (#28532) — never register.
        // Builtin class kept for spine; getSettableType stays gated on 8.4+.
        if (CompilerVersion::supportsReflectionPropertyGetReadableType()) {
            $rp->methods['getreadabletype'] = new ReflectionPropertyGetReadableType();
            $rp->methodVisibility['getreadabletype'] = $pub;
        }
        if (CompilerVersion::supportsReflectionPropertyReadableSettableType()) {
            $rp->methods['getsettabletype'] = new ReflectionPropertyGetSettableType();
            $rp->methodVisibility['getsettabletype'] = $pub;
        }
        if (CompilerVersion::supportsAsymmetricVisibility()) {
            // php-src ReflectionProperty exposes isPrivateSet/isProtectedSet only — no isPublicSet
            // (#28185, ext/reflection/php_reflection.stub.php).
            foreach (
                [
                    'isprivateset' => ReflectionPropertyAsymmetricProbe::isPrivateSet(),
                    'isprotectedset' => ReflectionPropertyAsymmetricProbe::isProtectedSet(),
                    'isprivateget' => ReflectionPropertyAsymmetricProbe::isPrivateGet(),
                    'isprotectedget' => ReflectionPropertyAsymmetricProbe::isProtectedGet(),
                    'ispublicget' => ReflectionPropertyAsymmetricProbe::isPublicGet(),
                    'getasymmetricvisibility' => new ReflectionPropertyGetAsymmetricVisibility(),
                ] as $name => $method
            ) {
                $rp->methods[$name] = $method;
                $rp->methodVisibility[$name] = $pub;
            }
        }
        if (CompilerVersion::supportsReflectionPropertyHookProbes()) {
            // php-src ReflectionProperty has getHook/getHooks only — no setHook (#22494, re-#22116).
            foreach (
                [
                    'isabstract' => new ReflectionPropertyIsAbstract(),
                    'isvirtual' => new ReflectionPropertyIsVirtual(),
                    'isfinal' => new ReflectionPropertyIsFinal(),
                    'hashook' => new ReflectionPropertyHasHook(),
                    'hashooks' => new ReflectionPropertyHasHooks(),
                    'gethook' => new ReflectionPropertyGetHook(),
                    'gethooks' => new ReflectionPropertyGetHooks(),
                    'islazy' => new ReflectionPropertyIsLazy(),
                    'setrawvaluewithoutlazyinitialization' => new ReflectionPropertySetRawValueWithoutLazyInitialization(),
                    'skiplazyinitialization' => new ReflectionPropertySkipLazyInitialization(),
                ] as $name => $method
            ) {
                $rp->methods[$name] = $method;
                $rp->methodVisibility[$name] = $pub;
            }
        }
        if (CompilerVersion::supportsReflectionPropertyAccessProbes()) {
            $rp->methods['isreadable'] = ReflectionPropertyAccessProbe::isReadable();
            $rp->methodVisibility['isreadable'] = $pub;
            $rp->methods['iswritable'] = ReflectionPropertyAccessProbe::isWritable();
            $rp->methodVisibility['iswritable'] = $pub;
        }
        if (CompilerVersion::supportsReflectionPropertyIsDynamic()) {
            $rp->methods['isdynamic'] = new ReflectionPropertyIsDynamic();
            $rp->methodVisibility['isdynamic'] = $pub;
        }
        // ReflectionProperty::isDeprecated: phantom vs php-src (#28529) — never register.
        if (CompilerVersion::supportsReflectionPropertyParameterIsDeprecated()) {
            $rp->methods['isdeprecated'] = new ReflectionPropertyIsDeprecated();
            $rp->methodVisibility['isdeprecated'] = $pub;
        }
        $rp->methods['__tostring'] = new ReflectionPropertyToString();
        $rp->methodVisibility['__tostring'] = $pub;
        $ctx->classes[ReflectionSupport::REFLECTION_PROPERTY] = $rp;

        $rf = new ClassEntry('ReflectionFunction');
        $rf->parentLc = ReflectionSupport::REFLECTION_FUNCTION_ABSTRACT;
        // Zend public dump surface is `$name` (#22488); do not expose internal `funcName`.
        $rf->properties[] = new ClassProperty(ReflectionSupport::PROP_REFLECTION_FUNCTION_NAME, null, $strProto);
        // ref->accessible is C-only in php-src — not a PHP property (#22514).
        $rfAccess = new ClassProperty(ReflectionSupport::PROP_ACCESSIBLE, $boolFalseDefault, $boolProto);
        $rfAccess->phpInvisible = true;
        $rf->properties[] = $rfAccess;
        $rf->constructor = new ReflectionFunctionConstruct();
        $rf->methods['__construct'] = $rf->constructor;
        $rf->methodVisibility['__construct'] = $pub;
        foreach (
            [
                'getname' => new ReflectionFunctionGetName(),
                'getparameters' => new ReflectionFunctionGetParameters(),
                'getnumberofparameters' => new ReflectionFunctionGetNumberOfParameters(),
                'getnumberofrequiredparameters' => new ReflectionFunctionGetNumberOfRequiredParameters(),
                'getreturntype' => new ReflectionFunctionGetReturnType(),
                'hasreturntype' => new ReflectionFunctionHasReturnType(),
                'gettentativereturntype' => new ReflectionFunctionGetTentativeReturnType(),
                'hastentativereturntype' => new ReflectionFunctionHasTentativeReturnType(),
                'isanonymous' => new ReflectionFunctionIsAnonymous(),
                'isclosure' => new ReflectionFunctionIsClosure(),
                'isdisabled' => new ReflectionFunctionIsDisabled(),
                'isgenerator' => new ReflectionFunctionIsGenerator(),
                'isinternal' => new ReflectionFunctionIsInternal(),
                'isstatic' => new ReflectionFunctionIsStatic(),
                'isuserdefined' => new ReflectionFunctionIsUserDefined(),
                'isvariadic' => new ReflectionFunctionIsVariadic(),
                'returnsreference' => new ReflectionFunctionReturnsReference(),
                'getextensionname' => new ReflectionFunctionGetExtensionName(),
                'getextension' => new ReflectionFunctionGetExtension(),
                'getfilename' => new ReflectionFunctionGetFileName(),
                'getdoccomment' => new ReflectionFunctionGetDocComment(),
                'getstartline' => new ReflectionFunctionGetStartLine(),
                'getendline' => new ReflectionFunctionGetEndLine(),
                'getnamespacename' => new ReflectionFunctionGetNamespaceName(),
                'getshortname' => new ReflectionFunctionGetShortName(),
                'innamespace' => new ReflectionFunctionInNamespace(),
                'getclosurethis' => new ReflectionFunctionGetClosureThis(),
                'getclosure' => new ReflectionFunctionGetClosure(),
                'getclosurescopeclass' => new ReflectionFunctionGetClosureScopeClass(),
                'getclosurecalledclass' => new ReflectionFunctionGetClosureCalledClass(),
                'getclosureusedvariables' => new ReflectionFunctionGetClosureUsedVariables(),
                'getstaticvariables' => new ReflectionFunctionGetStaticVariables(),
                'invoke' => new ReflectionFunctionInvoke(),
                'invokeargs' => new ReflectionFunctionInvokeArgs(),
                // ReflectionFunction has no setAccessible/isAccessible in php-src (#22512).
            ] as $name => $method
        ) {
            $rf->methods[$name] = $method;
            $rf->methodVisibility[$name] = $pub;
        }
        if (CompilerVersion::supportsReflectionCreateFromFactories()) {
            $rf->methods['createfromcallable'] = new ReflectionFunctionCreateFromCallable();
            $rf->methodVisibility['createfromcallable'] = $pubStatic;
            $rf->methods['createfromfunction'] = new ReflectionFunctionCreateFromFunction();
            $rf->methodVisibility['createfromfunction'] = $pubStatic;
        }
        $rf->methods['isdeprecated'] = new ReflectionFunctionIsDeprecated();
        $rf->methodVisibility['isdeprecated'] = $pub;
        if (CompilerVersion::supportsReflectionFunctionGetNamedArguments()) {
            $getNamedArguments = new ReflectionFunctionGetNamedArguments();
            $rf->methods['getnamedarguments'] = $getNamedArguments;
            $rf->methodVisibility['getnamedarguments'] = $pub;
        }
        $rf->methods['__tostring'] = new ReflectionFunctionToString();
        $rf->methodVisibility['__tostring'] = $pub;
        \PHPCompiler\ext\standard\VmReflection::registerReflectionFunctionClassConstants($rf);
        $ctx->classes[ReflectionSupport::REFLECTION_FUNCTION] = $rf;

        if (CompilerVersion::advertisesReflectionConstantClass()) {
            $rconst = new ClassEntry('ReflectionConstant');
            $rconst->properties[] = new ClassProperty(ReflectionSupport::PROP_CLASS_NAME, null, $strProto);
            $rconst->properties[] = new ClassProperty(ReflectionSupport::PROP_CONSTANT_NAME, null, $strProto);
            $rconst->constructor = new ReflectionConstantConstruct();
            $rconst->methods['__construct'] = $rconst->constructor;
            $rconst->methodVisibility['__construct'] = $pub;
            $rconst->methods['getname'] = new ReflectionConstantGetName();
            $rconst->methodVisibility['getname'] = $pub;
            $rconst->methods['getnamespacename'] = new ReflectionConstantGetNamespaceName();
            $rconst->methodVisibility['getnamespacename'] = $pub;
            $rconst->methods['getshortname'] = new ReflectionConstantGetShortName();
            $rconst->methodVisibility['getshortname'] = $pub;
            $rconst->methods['__tostring'] = new ReflectionConstantToString();
            $rconst->methodVisibility['__tostring'] = $pub;
            if (CompilerVersion::advertisesReflectionConstantFileExtensionApis()) {
                $rconst->methods['getfilename'] = new ReflectionConstantGetFileName();
                $rconst->methodVisibility['getfilename'] = $pub;
                $rconst->methods['getextension'] = new ReflectionConstantGetExtension();
                $rconst->methodVisibility['getextension'] = $pub;
                $rconst->methods['getextensionname'] = new ReflectionConstantGetExtensionName();
                $rconst->methodVisibility['getextensionname'] = $pub;
            }
            if (CompilerVersion::advertisesReflectionConstantInNamespace()) {
                $rconst->methods['innamespace'] = new ReflectionConstantInNamespace();
                $rconst->methodVisibility['innamespace'] = $pub;
            }
            $rconst->methods['getvalue'] = new ReflectionConstantGetValue();
            $rconst->methodVisibility['getvalue'] = $pub;
            // PHP 8.5+ only — absent on PHP-8.4 stubs (#28157).
            if (CompilerVersion::advertisesReflectionConstantGetAttributes()) {
                $rconst->methods['getattributes'] = new ReflectionConstantGetAttributes();
                $rconst->methodVisibility['getattributes'] = $pub;
            }
            // php-src ReflectionConstant never exposes ReflectionClassConstant APIs
            // (getDeclaringClass/getModifiers/getType/is*/getDeprecatedMessage/Version) — #28156.
            if (CompilerVersion::supportsReflectionClassConstantIsDeprecated()) {
                $rconst->methods['isdeprecated'] = new ReflectionConstantIsDeprecated();
                $rconst->methodVisibility['isdeprecated'] = $pub;
            }
            $ctx->classes[ReflectionSupport::REFLECTION_CONSTANT] = $rconst;
        }

        $rcc = new ClassEntry('ReflectionClassConstant');
        // Zend dump/public surface: $name = constant, $class = declaring class (#22503).
        // Property registration order matches Zend print_r/var_dump (name then class).
        $rcc->properties[] = new ClassProperty(ReflectionSupport::PROP_REFLECTION_CLASS_CONSTANT_NAME, null, $strProto);
        $rcc->properties[] = new ClassProperty(ReflectionSupport::PROP_REFLECTION_CLASS_CONSTANT_CLASS, null, $strProto);
        $rcc->constructor = new ReflectionConstantConstruct();
        $rcc->methods['__construct'] = $rcc->constructor;
        $rcc->methodVisibility['__construct'] = $pub;
        $rcc->methods['getname'] = new ReflectionConstantGetName();
        $rcc->methodVisibility['getname'] = $pub;
        $rcc->methods['getvalue'] = new ReflectionConstantGetValue();
        $rcc->methodVisibility['getvalue'] = $pub;
        $rcc->methods['getattributes'] = new ReflectionConstantGetAttributes();
        $rcc->methodVisibility['getattributes'] = $pub;
        $rcc->methods['getdeclaringclass'] = new ReflectionConstantGetDeclaringClass();
        $rcc->methodVisibility['getdeclaringclass'] = $pub;
        $rcc->methods['gettype'] = new ReflectionClassConstantGetType();
        $rcc->methodVisibility['gettype'] = $pub;
        $rcc->methods['hastype'] = new ReflectionClassConstantHasType();
        $rcc->methodVisibility['hastype'] = $pub;
        $rcc->methods['getmodifiers'] = new ReflectionClassConstantGetModifiers();
        $rcc->methodVisibility['getmodifiers'] = $pub;
        $rcc->methods['getdoccomment'] = new ReflectionClassConstantGetDocComment();
        $rcc->methodVisibility['getdoccomment'] = $pub;
        $rcc->methods['__tostring'] = new ReflectionClassConstantToString();
        $rcc->methodVisibility['__tostring'] = $pub;
        if (CompilerVersion::supportsReflectionClassConstantIsDeprecated()) {
            $rcc->methods['isdeprecated'] = new ReflectionClassConstantIsDeprecated();
            $rcc->methodVisibility['isdeprecated'] = $pub;
            $rcc->methods['getdeprecatedmessage'] = new ReflectionClassConstantGetDeprecatedMessage();
            $rcc->methodVisibility['getdeprecatedmessage'] = $pub;
            $rcc->methods['getdeprecatedversion'] = new ReflectionClassConstantGetDeprecatedVersion();
            $rcc->methodVisibility['getdeprecatedversion'] = $pub;
        }
        $rcc->methods['isfinal'] = new ReflectionClassConstantIsFinal();
        $rcc->methodVisibility['isfinal'] = $pub;
        $rcc->methods['isenumcase'] = new ReflectionClassConstantIsEnumCase();
        $rcc->methodVisibility['isenumcase'] = $pub;
        $rcc->methods['ispublic'] = new ReflectionClassConstantIsPublic();
        $rcc->methodVisibility['ispublic'] = $pub;
        $rcc->methods['isprotected'] = new ReflectionClassConstantIsProtected();
        $rcc->methodVisibility['isprotected'] = $pub;
        $rcc->methods['isprivate'] = new ReflectionClassConstantIsPrivate();
        $rcc->methodVisibility['isprivate'] = $pub;
        \PHPCompiler\ext\standard\VmReflection::registerReflectionClassConstantClassConstants($rcc);
        $ctx->classes[ReflectionSupport::REFLECTION_CLASS_CONSTANT] = $rcc;

        \PHPCompiler\ext\standard\VmReflection::registerReflectionClassClassConstants($rc);
        $ctx->classes[ReflectionSupport::REFLECTION_CLASS] = $rc;

        $objProto = new Variable(Variable::TYPE_OBJECT);
        $ro = new ClassEntry('ReflectionObject');
        // php-src: class ReflectionObject extends ReflectionClass (ext/reflection/php_reflection.stub.php, #20098).
        $ro->parentLc = ReflectionSupport::REFLECTION_CLASS;
        $ro->properties[] = new ClassProperty(ReflectionSupport::PROP_CLASS_NAME, null, $strProto);
        // Wrapped instance handle — C storage only; get_object_vars exports name (#22515).
        $roTarget = new ClassProperty(ReflectionSupport::PROP_OBJECT_TARGET, null, $objProto);
        $roTarget->phpInvisible = true;
        $ro->properties[] = $roTarget;
        $ro->constructor = new ReflectionObjectConstruct();
        $ro->methods['__construct'] = $ro->constructor;
        $ro->methodVisibility['__construct'] = $pub;
        $ctx->classes[ReflectionSupport::REFLECTION_OBJECT] = $ro;

        $renum = new ClassEntry('ReflectionEnum');
        // php-src: class ReflectionEnum extends ReflectionClass (ext/reflection/php_reflection.stub.php, #19740).
        $renum->parentLc = ReflectionSupport::REFLECTION_CLASS;
        $renum->properties[] = new ClassProperty(ReflectionSupport::PROP_CLASS_NAME, null, $strProto);
        $renum->constructor = new ReflectionEnumConstruct();
        $renum->methods['__construct'] = $renum->constructor;
        $renum->methodVisibility['__construct'] = $pub;
        $renum->methods['getname'] = new ReflectionEnumGetName();
        $renum->methodVisibility['getname'] = $pub;
        $renum->methods['isbacked'] = new ReflectionEnumIsBacked();
        $renum->methodVisibility['isbacked'] = $pub;
        $renum->methods['getbackingtype'] = new ReflectionEnumGetBackingType();
        $renum->methodVisibility['getbackingtype'] = $pub;
        $renum->methods['getcases'] = new ReflectionEnumGetCases();
        $renum->methodVisibility['getcases'] = $pub;
        $renum->methods['getcase'] = new ReflectionEnumGetCase();
        $renum->methodVisibility['getcase'] = $pub;
        $renum->methods['hascase'] = new ReflectionEnumHasCase();
        $renum->methodVisibility['hascase'] = $pub;
        if (CompilerVersion::supportsReflectionEnumFromName()) {
            $renum->methods['fromname'] = new ReflectionEnumFromName();
            $renum->methodVisibility['fromname'] = $pubStatic;
            $renum->methodNames['fromname'] = 'fromName';
        }
        $renum->methods['gettraitnames'] = new ReflectionClassGetTraitNames();
        $renum->methods['gettraits'] = new ReflectionClassGetTraits();
        $renum->methods['getinterfacenames'] = new ReflectionClassGetInterfaceNames();
        $renum->methods['getinterfaces'] = new ReflectionClassGetInterfaces();
        $renum->methodVisibility['gettraitnames'] = $pub;
        $renum->methodVisibility['gettraits'] = $pub;
        $renum->methodVisibility['getinterfacenames'] = $pub;
        $renum->methodVisibility['getinterfaces'] = $pub;
        $ctx->classes[ReflectionSupport::REFLECTION_ENUM] = $renum;

        $reuc = new ClassEntry('ReflectionEnumUnitCase');
        // php-src: class ReflectionEnumUnitCase extends ReflectionClassConstant (#19785).
        $reuc->parentLc = ReflectionSupport::REFLECTION_CLASS_CONSTANT;
        $reuc->properties[] = new ClassProperty(ReflectionSupport::PROP_CLASS_NAME, null, $strProto);
        $reuc->properties[] = new ClassProperty(ReflectionSupport::PROP_ENUM_CLASS_NAME, null, $strProto);
        $reuc->constructor = new ReflectionEnumUnitCaseConstruct();
        $reuc->methods['__construct'] = $reuc->constructor;
        $reuc->methodVisibility['__construct'] = $pub;
        $reuc->methods['getattributes'] = new ReflectionEnumUnitCaseGetAttributes();
        $reuc->methodVisibility['getattributes'] = $pub;
        $reuc->methods['getname'] = new ReflectionEnumUnitCaseGetName();
        $reuc->methodVisibility['getname'] = $pub;
        $reuc->methods['getvalue'] = new ReflectionEnumUnitCaseGetValue();
        $reuc->methodVisibility['getvalue'] = $pub;
        $reuc->methods['getenum'] = new ReflectionEnumUnitCaseGetEnum();
        $reuc->methodVisibility['getenum'] = $pub;
        $reuc->methods['getdeclaringclass'] = new ReflectionEnumUnitCaseGetDeclaringClass();
        $reuc->methodVisibility['getdeclaringclass'] = $pub;
        $reuc->methods['getdoccomment'] = new ReflectionEnumUnitCaseGetDocComment();
        $reuc->methodVisibility['getdoccomment'] = $pub;
        $reuc->methods['__tostring'] = new ReflectionEnumUnitCaseToString();
        $reuc->methodVisibility['__tostring'] = $pub;
        if (CompilerVersion::supportsReflectionEnumCaseIsBacked()) {
            $reuc->methods['isbacked'] = new ReflectionEnumUnitCaseIsBacked();
            $reuc->methodVisibility['isbacked'] = $pub;
        }
        if (CompilerVersion::supportsReflectionEnumUnitCaseIsDeprecated()) {
            $reuc->methods['isdeprecated'] = new ReflectionEnumUnitCaseIsDeprecated();
            $reuc->methodVisibility['isdeprecated'] = $pub;
        }
        $ctx->classes[ReflectionSupport::REFLECTION_ENUM_UNIT_CASE] = $reuc;

        $rebc = new ClassEntry('ReflectionEnumBackedCase');
        $rebc->parentLc = ReflectionSupport::REFLECTION_ENUM_UNIT_CASE;
        $rebc->properties[] = new ClassProperty(ReflectionSupport::PROP_CLASS_NAME, null, $strProto);
        $rebc->properties[] = new ClassProperty(ReflectionSupport::PROP_ENUM_CLASS_NAME, null, $strProto);
        $rebc->constructor = new ReflectionEnumBackedCaseConstruct();
        $rebc->methods['__construct'] = $rebc->constructor;
        $rebc->methodVisibility['__construct'] = $pub;
        $rebc->methods['getbackingvalue'] = new ReflectionEnumBackedCaseGetBackingValue();
        $rebc->methodVisibility['getbackingvalue'] = $pub;
        if (CompilerVersion::supportsReflectionEnumCaseIsBacked()) {
            $rebc->methods['isbacked'] = new ReflectionEnumBackedCaseIsBacked();
            $rebc->methodVisibility['isbacked'] = $pub;
        }
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
            [
                'gettypes' => new ReflectionCompositeTypeGetTypes(),
            ]
        );
        self::registerReflectionTypeClass(
            $ctx,
            'ReflectionIntersectionType',
            ReflectionSupport::REFLECTION_INTERSECTION_TYPE,
            $strProto,
            $boolProto,
            $arrayProto,
            $pub,
            [
                'gettypes' => new ReflectionCompositeTypeGetTypes(),
            ]
        );

        $objProto = new Variable(Variable::TYPE_OBJECT);
        $rfiber = new ClassEntry('ReflectionFiber');
        $rfiber->properties[] = new ClassProperty(ReflectionSupport::PROP_FIBER_TARGET, null, $objProto);
        $rfiber->constructor = new ReflectionFiberConstruct();
        $rfiber->methods['__construct'] = $rfiber->constructor;
        $rfiber->methodVisibility['__construct'] = $pub;
        // Fiber state probes (isStarted/isSuspended/isRunning/isTerminated) live on Fiber
        // only — php-src ReflectionFiber does not advertise them (#22422).
        // getExecutingFiber is not a php-src ReflectionFiber API (#25058; was non-Zend #6793).
        foreach (
            [
                'getfiber' => new ReflectionFiberGetFiber(),
                'getexecutingline' => new ReflectionFiberGetExecutingLine(),
                'getexecutingfile' => new ReflectionFiberGetExecutingFile(),
                'gettrace' => new ReflectionFiberGetTrace(),
                'getcallable' => new ReflectionFiberGetCallable(),
            ] as $name => $method
        ) {
            $rfiber->methods[$name] = $method;
            $rfiber->methodVisibility[$name] = $pub;
        }
        $ctx->classes[ReflectionSupport::REFLECTION_FIBER] = $rfiber;

        $rgen = new ClassEntry('ReflectionGenerator');
        $rgen->properties[] = new ClassProperty(ReflectionSupport::PROP_GENERATOR_TARGET, null, $objProto);
        $rgen->constructor = new ReflectionGeneratorConstruct();
        $rgen->methods['__construct'] = $rgen->constructor;
        $rgen->methodVisibility['__construct'] = $pub;
        foreach (
            [
                'getfunction' => new ReflectionGeneratorGetFunction(),
                'getexecutingline' => new ReflectionGeneratorGetExecutingLine(),
                'getexecutingfile' => new ReflectionGeneratorGetExecutingFile(),
                'getexecutinggenerator' => new ReflectionGeneratorGetExecutingGenerator(),
                'getthis' => new ReflectionGeneratorGetThis(),
                'gettrace' => new ReflectionGeneratorGetTrace(),
            ] as $name => $method
        ) {
            $rgen->methods[$name] = $method;
            $rgen->methodVisibility[$name] = $pub;
        }
        if (CompilerVersion::supportsReflectionGeneratorIsClosed()) {
            $rgen->methods['isclosed'] = new ReflectionGeneratorIsClosed();
            $rgen->methodVisibility['isclosed'] = $pub;
        }
        $ctx->classes[ReflectionSupport::REFLECTION_GENERATOR] = $rgen;

        $rref = new ClassEntry('ReflectionReference');
        $rref->isFinal = true;
        $rref->properties[] = new ClassProperty(ReflectionSupport::PROP_REFLECTION_REFERENCE_ID, null, $strProto);
        $rref->constructor = new ReflectionReferenceConstruct();
        $rref->methods['__construct'] = $rref->constructor;
        $rref->methodVisibility['__construct'] = $pub;
        $rref->methods['fromarrayelement'] = new ReflectionReferenceFromArrayElement();
        $rref->methodVisibility['fromarrayelement'] = $pubStatic;
        $rref->methodNames['fromarrayelement'] = 'fromArrayElement';
        $rref->methods['getid'] = new ReflectionReferenceGetId();
        $rref->methodVisibility['getid'] = $pub;
        $rref->methodNames['getid'] = 'getId';
        $ctx->classes[ReflectionSupport::REFLECTION_REFERENCE] = $rref;
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
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;

        $tz = new ClassEntry('DateTimeZone');
        DateTimeZoneSupport::registerClassConstants($tz);
        // Engine storage — not in Zend's PHP property table (#26155).
        $tzName = new ClassProperty(DateTimeSupport::TZ_NAME_PROPERTY, null, $strProto);
        $tzName->phpInvisible = true;
        $tz->properties[] = $tzName;
        $tz->constructor = new DateTimeZoneConstruct();
        $tz->methods['__construct'] = $tz->constructor;
        $tz->methodVisibility['__construct'] = $pub;
        $tz->methods['getname'] = new DateTimeZoneGetName();
        $tz->methodVisibility['getname'] = $pub;
        $tz->methods['getoffset'] = new DateTimeZoneGetOffset();
        $tz->methodVisibility['getoffset'] = $pub;
        $tz->methods['getlocation'] = new DateTimeZoneGetLocation();
        $tz->methodVisibility['getlocation'] = $pub;
        $tz->methods['gettransitions'] = new DateTimeZoneGetTransitions();
        $tz->methodVisibility['gettransitions'] = $pub;
        $tz->methods['listabbreviations'] = new DateTimeZoneListAbbreviations();
        $tz->methodVisibility['listabbreviations'] = $pubStatic;
        $tz->methods['listidentifiers'] = new DateTimeZoneListIdentifiers();
        $tz->methodVisibility['listidentifiers'] = $pubStatic;
        $tz->methods['__set_state'] = new DateTimeZoneSetState();
        $tz->methodVisibility['__set_state'] = $pubStatic;
        $tz->methodNames['__set_state'] = '__set_state';
        DateSerializeMethods::registerOnDateTimeZone($tz, $pub);
        $ctx->classes[DateTimeSupport::CLASS_DATETIMEZONE] = $tz;

        $dateTimeMethods = [
            'format' => new DateTimeFormat(),
            'gettimestamp' => new DateTimeGetTimestamp(),
            'getoffset' => new DateTimeGetOffset(),
            'gettimezone' => new DateTimeGetTimezone(),
            ...(CompilerVersion::supportsDateTimeMicrosecond() ? [
                'getmicrosecond' => new DateTimeGetMicrosecond(),
            ] : []),
            'modify' => new DateTimeModify(),
            'add' => new DateTimeAdd(),
            'sub' => new DateTimeSub(),
            'diff' => new DateTimeDiff(),
            'setdate' => new DateTimeSetDate(),
            'setisodate' => new DateTimeSetISODate(),
            'settime' => new DateTimeSetTime(),
            ...(CompilerVersion::supportsDateTimeMicrosecond() ? [
                'setmicrosecond' => new DateTimeSetMicrosecond(),
            ] : []),
            'settimestamp' => new DateTimeSetTimestamp(),
            'settimezone' => new DateTimeSetTimezone(),
        ];

        $dt = new ClassEntry('DateTime');
        $dt->interfaces = [DateTimeSupport::CLASS_DATETIMEINTERFACE];
        DateTimeInterfaceSupport::registerClassConstants($dt);
        // Engine storage — not in Zend's PHP property table (#26155).
        foreach ([
            DateTimeSupport::TS_PROPERTY,
            DateTimeSupport::TZ_PROPERTY,
            DateTimeSupport::MICROSECOND_PROPERTY,
        ] as $dtPropName) {
            $proto = DateTimeSupport::TS_PROPERTY === $dtPropName
                || DateTimeSupport::MICROSECOND_PROPERTY === $dtPropName
                ? $intProto
                : $strProto;
            $dtProp = new ClassProperty($dtPropName, null, $proto);
            $dtProp->phpInvisible = true;
            $dt->properties[] = $dtProp;
        }
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
        if (CompilerVersion::supportsDateTimeCreateFromTimestamp()) {
            $dt->methods['createfromtimestamp'] = new DateTimeCreateFromTimestamp();
            $dt->methodVisibility['createfromtimestamp'] = $pubStatic;
        }
        $dt->methods['getlasterrors'] = new DateTimeGetLastErrors();
        $dt->methodVisibility['getlasterrors'] = $pubStatic;
        // php-src php_date.stub.php — getMicrosecond(): int; setMicrosecond(): static (#28711)
        if (CompilerVersion::supportsDateTimeMicrosecond()) {
            $intRet = ReflectionTypeSupport::cfgTypeFromLabel('int');
            $staticRet = ReflectionTypeSupport::cfgTypeFromLabel('static');
            if (null !== $intRet) {
                $dt->methodReturnDeclaredTypes['getmicrosecond'] = $intRet;
            }
            if (null !== $staticRet) {
                $dt->methodReturnDeclaredTypes['setmicrosecond'] = $staticRet;
            }
        }
        $dt->methods['__set_state'] = new DateTimeSetState(
            DateTimeSupport::CLASS_DATETIME,
            'DateTime'
        );
        $dt->methodVisibility['__set_state'] = $pubStatic;
        $dt->methodNames['__set_state'] = '__set_state';
        DateSerializeMethods::registerOnDateTimeLike(
            $dt,
            DateTimeSupport::CLASS_DATETIME,
            'DateTime',
            $pub
        );
        // php-src date_object_clone_date — mark clone initialized after shallow prop copy (#22892).
        $dt->cloneObjectHandler = [DateTimeSupport::class, 'cloneInto'];
        $ctx->classes[DateTimeSupport::CLASS_DATETIME] = $dt;

        $dti = new ClassEntry('DateTimeImmutable');
        $dti->interfaces = [DateTimeSupport::CLASS_DATETIMEINTERFACE];
        DateTimeInterfaceSupport::registerClassConstants($dti);
        // Engine storage — not in Zend's PHP property table (#26155).
        foreach ([
            DateTimeSupport::TS_PROPERTY,
            DateTimeSupport::TZ_PROPERTY,
            DateTimeSupport::MICROSECOND_PROPERTY,
        ] as $dtiPropName) {
            $proto = DateTimeSupport::TS_PROPERTY === $dtiPropName
                || DateTimeSupport::MICROSECOND_PROPERTY === $dtiPropName
                ? $intProto
                : $strProto;
            $dtiProp = new ClassProperty($dtiPropName, null, $proto);
            $dtiProp->phpInvisible = true;
            $dti->properties[] = $dtiProp;
        }
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
        if (CompilerVersion::supportsDateTimeCreateFromTimestamp()) {
            $dti->methods['createfromtimestamp'] = new DateTimeImmutableCreateFromTimestamp();
            $dti->methodVisibility['createfromtimestamp'] = $pubStatic;
        }
        $dti->methods['getlasterrors'] = new DateTimeGetLastErrors();
        $dti->methodVisibility['getlasterrors'] = $pubStatic;
        // php-src php_date.stub.php — getMicrosecond(): int; setMicrosecond(): static (#28711)
        if (CompilerVersion::supportsDateTimeMicrosecond()) {
            $intRet = ReflectionTypeSupport::cfgTypeFromLabel('int');
            $staticRet = ReflectionTypeSupport::cfgTypeFromLabel('static');
            if (null !== $intRet) {
                $dti->methodReturnDeclaredTypes['getmicrosecond'] = $intRet;
            }
            if (null !== $staticRet) {
                $dti->methodReturnDeclaredTypes['setmicrosecond'] = $staticRet;
            }
        }
        $dti->methods['__set_state'] = new DateTimeSetState(
            DateTimeSupport::CLASS_DATETIMEIMMUTABLE,
            'DateTimeImmutable'
        );
        $dti->methodVisibility['__set_state'] = $pubStatic;
        $dti->methodNames['__set_state'] = '__set_state';
        DateSerializeMethods::registerOnDateTimeLike(
            $dti,
            DateTimeSupport::CLASS_DATETIMEIMMUTABLE,
            'DateTimeImmutable',
            $pub
        );
        // php-src date_object_clone_date — mark clone initialized after shallow prop copy (#22892).
        $dti->cloneObjectHandler = [DateTimeSupport::class, 'cloneInto'];
        $ctx->classes[DateTimeSupport::CLASS_DATETIMEIMMUTABLE] = $dti;

        $floatProto = new Variable(Variable::TYPE_FLOAT);
        $boolProto = new Variable(Variable::TYPE_BOOLEAN);

        $di = new ClassEntry('DateInterval');
        foreach (['y', 'm', 'd', 'h', 'i', 's', 'invert'] as $propName) {
            $di->properties[] = new ClassProperty($propName, null, $intProto);
        }
        $di->properties[] = new ClassProperty('f', null, $floatProto);
        $di->properties[] = new ClassProperty('days', null, $boolProto);
        // php-src: from_string / date_string live on php_interval_obj only — not direct $i->prop (#24334).
        $fromStringStorage = new ClassProperty(DateIntervalSupport::FROM_STRING_STORAGE, null, $boolProto);
        $fromStringStorage->phpInvisible = true;
        $di->properties[] = $fromStringStorage;
        // Typed string slot stays uninitialized until createFromDateString / from_string wire (#22893).
        // TYPE_STRING prototype without payload made cloneShallow read Variable::$string uninit.
        $dateStringProto = new Variable(Variable::TYPE_UNDEFINED);
        $dateStringProto->typeConstraint = Variable::TYPE_STRING;
        $dateStringProto->declaredTypeLabel = 'string';
        $dateStringStorage = new ClassProperty(DateIntervalSupport::DATE_STRING_STORAGE, null, $dateStringProto);
        $dateStringStorage->phpInvisible = true;
        $di->properties[] = $dateStringStorage;
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
        $di->methods['__set_state'] = new DateIntervalSetState();
        $di->methodVisibility['__set_state'] = $pubStatic;
        $di->methodNames['__set_state'] = '__set_state';
        DateSerializeMethods::registerOnDateInterval($di, $pub);
        $ctx->classes[DateIntervalSupport::CLASS_DATEINTERVAL] = $di;

        $dp = new ClassEntry('DatePeriod');
        DatePeriodSupport::registerClassConstants($dp);
        // php-src php_date.c / date.stub.php — IteratorAggregate + getIterator → InternalIterator (#22263).
        $dp->interfaces = ['iteratoraggregate'];
        // php-src @readonly via write handlers — not ClassProperty::$readonly (#26154, re-#26146).
        // Internal DatePeriodSupport / JIT iterator stores bypass userland Assign guards.
        // Declared types match php_date.stub.php so unset→read is typed-uninit Error (#26170).
        // Fresh Variable per property — shared prototypes/defaults corrupted sibling slots on Error.
        $dp->properties[] = new ClassProperty('start', null, self::datePeriodNullableDtiProto());
        $dp->properties[] = new ClassProperty('current', null, self::datePeriodNullableDtiNullProto());
        $dp->properties[] = new ClassProperty('end', null, self::datePeriodNullableDtiNullProto());
        $dp->properties[] = new ClassProperty('interval', null, self::datePeriodNullableIntervalProto());
        $dp->properties[] = new ClassProperty('recurrences', null, self::datePeriodIntProto());
        $dp->properties[] = new ClassProperty('include_start_date', null, self::datePeriodBoolProto());
        $dp->properties[] = new ClassProperty('include_end_date', null, self::datePeriodBoolProto());
        foreach ($dp->properties as $prop) {
            $prop->visibility = $pub;
            $prop->declaringClassLc = DatePeriodSupport::CLASS_DATEPERIOD;
        }
        $dp->constructor = new DatePeriodConstruct();
        $dp->methods['__construct'] = $dp->constructor;
        $dp->methodVisibility['__construct'] = $pub;
        // php-src DatePeriod is IteratorAggregate only — do not advertise Iterator
        // rewind/valid/current/key/next on the method table (#22608). Walk helpers
        // live in DatePeriodSupport / getIterator() InternalIterator snapshot (#22263).
        $dp->methods['getiterator'] = new DatePeriodGetIterator();
        $dp->methodVisibility['getiterator'] = $pub;
        $dp->methodNames['getiterator'] = 'getIterator';
        $dp->methods['getstartdate'] = new DatePeriodGetStartDate();
        $dp->methodVisibility['getstartdate'] = $pub;
        $dp->methods['getenddate'] = new DatePeriodGetEndDate();
        $dp->methodVisibility['getenddate'] = $pub;
        $dp->methods['getdateinterval'] = new DatePeriodGetDateInterval();
        $dp->methodVisibility['getdateinterval'] = $pub;
        $dp->methods['getrecurrences'] = new DatePeriodGetRecurrences();
        $dp->methodVisibility['getrecurrences'] = $pub;
        $dp->methods['getenddate'] = new DatePeriodGetEndDate();
        $dp->methodVisibility['getenddate'] = $pub;
        if (CompilerVersion::supportsDatePeriodCreateFromISO8601String()) {
            $dp->methods['createfromiso8601string'] = new DatePeriodCreateFromISO8601String();
            $dp->methodVisibility['createfromiso8601string'] = $pubStatic;
            $dp->methodNames['createfromiso8601string'] = 'createFromISO8601String';
            // php-src stub returns static (#27923).
            $staticRet = ReflectionTypeSupport::cfgTypeFromLabel('static');
            if (null !== $staticRet) {
                $dp->methodReturnDeclaredTypes['createfromiso8601string'] = $staticRet;
            }
        }
        $dp->methods['__set_state'] = new DatePeriodSetState();
        $dp->methodVisibility['__set_state'] = $pubStatic;
        $dp->methodNames['__set_state'] = '__set_state';
        DateSerializeMethods::registerOnDatePeriod($dp, $pub);
        $ctx->classes[DatePeriodSupport::CLASS_DATEPERIOD] = $dp;
    }

    /** php-src DatePeriod::$start — ?DateTimeInterface, starts uninitialized (#26170). */
    private static function datePeriodNullableDtiProto(): Variable
    {
        $proto = new Variable(Variable::TYPE_UNDEFINED);
        $proto->typeConstraint = Variable::TYPE_OBJECT;
        $proto->classConstraint = 'DateTimeInterface';
        $proto->declaredTypeLabel = '?DateTimeInterface';

        return $proto;
    }

    /** php-src DatePeriod::$current/$end — ?DateTimeInterface, default null (#26170). */
    private static function datePeriodNullableDtiNullProto(): Variable
    {
        $proto = new Variable(Variable::TYPE_NULL);
        $proto->typeConstraint = Variable::TYPE_OBJECT;
        $proto->classConstraint = 'DateTimeInterface';
        $proto->declaredTypeLabel = '?DateTimeInterface';

        return $proto;
    }

    /** php-src DatePeriod::$interval — ?DateInterval (#26170). */
    private static function datePeriodNullableIntervalProto(): Variable
    {
        $proto = new Variable(Variable::TYPE_UNDEFINED);
        $proto->typeConstraint = Variable::TYPE_OBJECT;
        $proto->classConstraint = 'DateInterval';
        $proto->declaredTypeLabel = '?DateInterval';

        return $proto;
    }

    private static function datePeriodIntProto(): Variable
    {
        $proto = new Variable(Variable::TYPE_UNDEFINED);
        $proto->typeConstraint = Variable::TYPE_INTEGER;
        $proto->declaredTypeLabel = 'int';

        return $proto;
    }

    private static function datePeriodBoolProto(): Variable
    {
        $proto = new Variable(Variable::TYPE_UNDEFINED);
        $proto->typeConstraint = Variable::TYPE_BOOLEAN;
        $proto->declaredTypeLabel = 'bool';

        return $proto;
    }

    private static function registerExceptions(Context $ctx): void
    {
        $throwable = new ClassEntry('Throwable');
        $throwable->isInterface = true;
        // php-src Zend/zend_exceptions.stub.php — interface Throwable extends Stringable (#25427).
        $throwable->interfaces = [StringableSupport::INTERFACE_LC];
        self::registerBuiltinInterfaceMethods($throwable, [
            'getMessage',
            'getCode',
            'getFile',
            'getLine',
            'getTrace',
            'getPrevious',
            'getTraceAsString',
            // __toString comes from Stringable (zend_exceptions.stub extends Stringable; #25427).
        ]);
        self::applyThrowableMethodReturnTypes($throwable);
        $ctx->classes[ThrowableManifest::LC_THROWABLE] = $throwable;

        foreach (ThrowableManifest::registrationOrder() as $className) {
            if (!ThrowableManifest::isAdvertised($className)) {
                continue;
            }
            self::registerThrowableClass(
                $ctx,
                $className,
                ThrowableManifest::lcKey($className),
                ThrowableManifest::parentLc($className)
            );
        }
    }

    /**
     * php-src Zend/zend_exceptions.stub.php — Throwable method return types (#25427, #25868).
     *
     * getCode() has no declared return in the stub. Applied on Throwable and on concrete
     * Exception/Error hierarchy entries so Reflection and LSP match Zend (subclassing the
     * roots used to fatal on untyped inherited getmessage vs Throwable::getmessage(): string).
     */
    private static function applyThrowableMethodReturnTypes(ClassEntry $entry): void
    {
        $returns = [
            'getmessage' => 'string',
            'getfile' => 'string',
            'getline' => 'int',
            'gettrace' => 'array',
            'getprevious' => '?Throwable',
            'gettraceasstring' => 'string',
        ];
        foreach ($returns as $methodLc => $label) {
            $type = ReflectionTypeSupport::cfgTypeFromLabel($label);
            if (null !== $type) {
                $entry->methodReturnDeclaredTypes[$methodLc] = $type;
            }
        }
    }

    /**
     * php-src Zend/zend_exceptions.stub.php — Exception/Error method table (#25868).
     *
     * Keys stay lowercase; methodNames keep Zend casing (getMessage not getmessage).
     * Declaring class is Exception or Error (roots), matching zend_exceptions.c.
     *
     * @return array<string, \PHPCompiler\Func\Internal>
     */
    private static function throwableInstanceMethods(): array
    {
        return [
            'getMessage' => new ExceptionGetMessage(),
            'getCode' => new ExceptionGetCode(),
            'getFile' => new ExceptionGetFile(),
            'getLine' => new ExceptionGetLine(),
            'getPrevious' => new ExceptionGetPrevious(),
            'getTrace' => new ExceptionGetTrace(),
            'getTraceAsString' => new ExceptionGetTraceAsString(),
            '__toString' => new ExceptionToString(),
        ];
    }

    /**
     * @param array<string, \PHPCompiler\Func\Internal> $methods
     */
    private static function registerThrowableInstanceMethods(
        ClassEntry $entry,
        array $methods,
        int $visibility,
        string $declaringLc
    ): void {
        foreach ($methods as $name => $method) {
            $lc = strtolower($name);
            $entry->methods[$lc] = $method;
            $entry->methodVisibility[$lc] = $visibility;
            $entry->methodNames[$lc] = $name;
            $entry->methodDeclaringClassLc[$lc] = $declaringLc;
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
        // php-src Zend/zend_fibers.stub.php — final class FiberError (#28389).
        if (ThrowableManifest::LC_FIBER_ERROR === $lcKey) {
            $entry->isFinal = true;
        }
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
        $entry->methodNames['__construct'] = '__construct';
        $entry->methodDeclaringClassLc['__construct'] = $lcKey;
        $entry->methods['__wakeup'] = new ExceptionWakeup();
        $entry->methodVisibility['__wakeup'] = $pub;
        $entry->methodNames['__wakeup'] = '__wakeup';
        $entry->methodDeclaringClassLc['__wakeup'] = $privateDeclaringLc;
        // php-src zend_exceptions.stub.php — private __clone on Exception/Error roots only (#25870).
        if (ThrowableManifest::LC_EXCEPTION === $lcKey || ThrowableManifest::LC_ERROR === $lcKey) {
            $entry->methods['__clone'] = new ExceptionClone();
            $entry->methodVisibility['__clone'] = $priv;
            $entry->methodNames['__clone'] = '__clone';
            $entry->methodDeclaringClassLc['__clone'] = $lcKey;
            $voidType = ReflectionTypeSupport::cfgTypeFromLabel('void');
            if (null !== $voidType) {
                $entry->methodReturnDeclaredTypes['__clone'] = $voidType;
            }
            // zend_exceptions.c — clone_obj = NULL; subclasses inherit via parent walk.
            $entry->denyClone = true;
        }
        self::registerThrowableInstanceMethods(
            $entry,
            self::throwableInstanceMethods(),
            $pub,
            $privateDeclaringLc
        );
        self::applyThrowableMethodReturnTypes($entry);
        if (ThrowableManifest::LC_ERROR_EXCEPTION === $lcKey) {
            $entry->methods['getseverity'] = new ErrorExceptionGetSeverity();
            $entry->methodVisibility['getseverity'] = $pub;
            $entry->methodNames['getseverity'] = 'getSeverity';
            $entry->methodDeclaringClassLc['getseverity'] = $lcKey;
        }
        $ctx->classes[$lcKey] = $entry;
    }

    /** Zend JsonSerializable interface (ext/json/php_json.c / php_json.stub.php, #3370, #28561). */
    private static function registerJsonSerializable(Context $ctx): void
    {
        $entry = new ClassEntry('JsonSerializable');
        $entry->isInterface = true;
        // Mirror Countable: abstract public method so method_exists/Reflection see jsonSerialize (#28561).
        self::registerBuiltinInterfaceMethods($entry, ['jsonSerialize']);
        // php-src stub declares jsonSerialize(): mixed — explicit Literal (CfgType\Mixed_ = undeclared).
        $entry->methodReturnDeclaredTypes['jsonserialize'] = new CfgType\Literal('mixed');
        $ctx->classes['jsonserializable'] = $entry;
    }

    private static function registerFiber(Context $ctx): void
    {
        $entry = new ClassEntry('Fiber');
        // php-src Zend/zend_fibers.stub.php — final class Fiber (#28389).
        $entry->isFinal = true;
        // ZEND_ACC_NO_DYNAMIC_PROPERTIES (zend_fibers.c; #26371).
        $entry->noDynamicProperties = true;
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
                // php-src Fiber has no getTrace/getTraceAsString — use ReflectionFiber::getTrace (#22562).
            ] as $name => $method
        ) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = ('suspend' === $name || 'getcurrent' === $name)
                ? $pubStatic
                : $pub;
        }
        // Zend/zend_fibers.stub.php — getCurrent(): ?Fiber (#27740).
        $getCurrentRet = ReflectionTypeSupport::cfgTypeFromLabel('?Fiber');
        if (null !== $getCurrentRet) {
            $entry->methodReturnDeclaredTypes['getcurrent'] = $getCurrentRet;
        }
        // Zend/zend_fibers.stub.php — getReturn(): mixed (#27746).
        // Explicit `mixed` is Literal — CfgType\Mixed_ means undeclared (#22064 / #27599).
        $entry->methodReturnDeclaredTypes['getreturn'] = new CfgType\Literal('mixed');
        $ctx->classes[FiberSupport::CLASS_FIBER] = $entry;
    }
}
