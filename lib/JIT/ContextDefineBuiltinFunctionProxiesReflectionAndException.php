<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\CompilerVersion;

/**
 * Reflection* / Exception / Throwable / Error thin-AOT Call proxies for {@see Context} (#36387).
 *
 * Extracted from {@see ContextDefineBuiltinFunctionProxies} so the Reflection /
 * Exception catalog stays a separate TU from the SPL iterator / SplFile* proxy
 * wiring (split-TU / size-budget ratchet toward ContextDefineBuiltinFunctionProxies
 * ≤ 450 lines after this cut, #36199 / #36403).
 *
 * Used via {@code use ContextDefineBuiltinFunctionProxiesReflectionAndException;} on
 * {@see Context}. Invoked from {@see ContextDefineBuiltinFunctionProxies::defineBuiltinFunctionProxies}
 * after PhpToken registration and before Fiber / Generator / ClosureBind helpers.
 *
 * No new C ABI. php-src analogy: zim_Reflection* / zend_exception_* method tables
 * live in ext/reflection/ and Zend/zend_exceptions.c beside the executor rather
 * than inside a monolithic MINIT catalog (ext/reflection/php_reflection.c,
 * Zend/zend_exceptions.c).
 */
trait ContextDefineBuiltinFunctionProxiesReflectionAndException
{
    private function defineBuiltinFunctionProxiesReflectionAndException(): void
    {
        $this->functionProxies['reflectionclass::__construct'] = new Call\ReflectionClassConstruct();
        $this->functionProxies['reflectionobject::__construct'] = new Call\ReflectionObjectConstruct();
        $this->functionProxies['reflectionclass::getname'] = new Call\ReflectionClassGetName();
        // Thin AOT: ReflectionObject::$name / getName empty without construct + TYPE_VALUE (#34001).
        $this->functionProxies['reflectionobject::getname'] = new Call\ReflectionObjectGetName();
        $this->functionProxies['reflectionclass::getshortname'] = new Call\ReflectionClassGetShortName();
        $this->functionProxies['reflectionclass::getnamespacename'] = new Call\ReflectionClassGetNamespaceName();
        $this->functionProxies['reflectionclass::innamespace'] = new Call\ReflectionClassInNamespace();
        $this->functionProxies['reflectionclass::getattributes'] = new Call\ReflectionClassGetAttributes();

        $this->functionProxies['reflectionclass::getmethod'] = new Call\ReflectionClassGetMethod();
        // Thin AOT: unbound getConstructor → unseeded ReflectionMethod → SIGSEGV (#34073).
        $this->functionProxies['reflectionclass::getconstructor'] = new Call\ReflectionClassGetConstructor();
        $this->functionProxies['reflectionclass::getproperty'] = new Call\ReflectionClassGetProperty();
        $this->functionProxies['reflectionclass::getreflectionconstant'] = new Call\ReflectionClassGetReflectionConstant();
        // Thin AOT: unbound hasMethod/hasProperty/hasConstant → NULL (#34072); VM #6301.
        $this->functionProxies['reflectionclass::hasmethod'] = new Call\ReflectionClassHasMember('hasMethod');
        $this->functionProxies['reflectionclass::hasproperty'] = new Call\ReflectionClassHasMember('hasProperty');
        $this->functionProxies['reflectionclass::hasconstant'] = new Call\ReflectionClassHasMember('hasConstant');
        // Thin AOT: unbound getConstant → NULL (#34093); VM ReflectionClassGetConstant (#6950).
        $this->functionProxies['reflectionclass::getconstant'] = new Call\ReflectionClassGetConstant();
        // Thin AOT: unbound getConstants → NULL (#34109); VM ReflectionClassGetConstants (#6950).
        $this->functionProxies['reflectionclass::getconstants'] = new Call\ReflectionClassGetConstants();
        // Thin AOT: unbound getReflectionConstants → NULL (#34119); VM #6662.
        $this->functionProxies['reflectionclass::getreflectionconstants'] = new Call\ReflectionClassGetReflectionConstants();
        // Thin AOT: unbound getFileName → NULL/SIGSEGV (#34096); VM ReflectionClassGetFileName (#7358).
        $this->functionProxies['reflectionclass::getfilename'] = new Call\ReflectionClassGetFileName();
        // Thin AOT: unbound getStartLine/getEndLine/getDocComment → NULL (#34106);
        // once-per-module helpers — inlined emit SIGSEGV under typed show() thrice (#34186).
        $this->functionProxies['reflectionclass::getstartline'] = new Call\ReflectionClassSourceLocationQuery('getStartLine');
        $this->functionProxies['reflectionclass::getendline'] = new Call\ReflectionClassSourceLocationQuery('getEndLine');
        $this->functionProxies['reflectionclass::getdoccomment'] = new Call\ReflectionClassSourceLocationQuery('getDocComment');
        // Thin AOT: unbound getInterfaceNames/getTraitNames → NULL (#34110); Object_ interface/trait tables.
        $this->functionProxies['reflectionclass::getinterfacenames'] = new Call\ReflectionClassNameListQuery('interfacenames');
        $this->functionProxies['reflectionclass::gettraitnames'] = new Call\ReflectionClassNameListQuery('traitnames');
        // Thin AOT: unbound getInterfaces/getTraits → NULL (#34121); VM #22170 / #22108.
        $this->functionProxies['reflectionclass::getinterfaces'] = new Call\ReflectionClassClassMapQuery('interfaces');
        $this->functionProxies['reflectionclass::gettraits'] = new Call\ReflectionClassClassMapQuery('traits');
        // Thin AOT: unbound getTraitAliases → NULL (#34129); VM #6661.
        $this->functionProxies['reflectionclass::gettraitaliases'] = new Call\ReflectionClassGetTraitAliases();
        // Thin AOT: unbound __toString → convert-to-string fatal (#34135); VM #22379.
        $this->functionProxies['reflectionclass::__tostring'] = new Call\ReflectionClassToString();
        // Thin AOT: unbound implementsInterface/isSubclassOf → NULL → false (#34080); VM #6302.
        $this->functionProxies['reflectionclass::implementsinterface'] = new Call\ReflectionClassRelationQuery('implementsInterface');
        $this->functionProxies['reflectionclass::issubclassof'] = new Call\ReflectionClassRelationQuery('isSubclassOf');
        // Thin AOT: isFinal used broken strcasecmp → always true (#34043); memcmp+fold table.
        $this->functionProxies['reflectionclass::isfinal'] = new Call\ReflectionClassIsFinal();
        // Thin AOT: unbound isInstantiable → NULL (#34027); VM has ReflectionClassIsInstantiable.
        $this->functionProxies['reflectionclass::isinstantiable'] = new Call\ReflectionClassIsInstantiable();
        // Thin AOT: unbound isInstance → NULL (#34098); VM #6302 / peer instanceof tables.
        $this->functionProxies['reflectionclass::isinstance'] = new Call\ReflectionClassIsInstance();
        // Thin AOT: unbound isCloneable → NULL (#34040); VM has ReflectionClassIsCloneable (#22109).
        $this->functionProxies['reflectionclass::iscloneable'] = new Call\ReflectionClassIsCloneable();
        // Thin AOT: unbound isAnonymous → NULL (#34057); VM has ReflectionClassIsAnonymous (#5105).
        $this->functionProxies['reflectionclass::isanonymous'] = new Call\ReflectionClassIsAnonymous();
        // Thin AOT: unbound kind queries → NULL (#34032); NestedJIT emitKindQuery fails verify.
        $this->functionProxies['reflectionclass::isinterface'] = new Call\ReflectionClassKindQuery('isInterface');
        $this->functionProxies['reflectionclass::isabstract'] = new Call\ReflectionClassKindQuery('isAbstract');
        $this->functionProxies['reflectionclass::istrait'] = new Call\ReflectionClassKindQuery('isTrait');
        $this->functionProxies['reflectionclass::isenum'] = new Call\ReflectionClassKindQuery('isEnum');
        // Thin AOT: unbound isInternal/isUserDefined/isReadOnly → NULL (#34067); peer #34032 tables.
        $this->functionProxies['reflectionclass::isinternal'] = new Call\ReflectionClassKindQuery('isInternal');
        $this->functionProxies['reflectionclass::isuserdefined'] = new Call\ReflectionClassKindQuery('isUserDefined');
        $this->functionProxies['reflectionclass::isreadonly'] = new Call\ReflectionClassKindQuery('isReadOnly');
        // Thin AOT: isIterable looked up unlinked NestedJIT ABI → compile abort (#34062).
        $this->functionProxies['reflectionclass::isiterateable'] = new Call\ReflectionClassIsIterateable();
        $this->functionProxies['reflectionclass::isiterable'] = new Call\ReflectionClassIsIterateable();
        // Thin AOT: getParentClass without proxy → SIGSEGV on result use (#34069).
        $this->functionProxies['reflectionclass::getparentclass'] = new Call\ReflectionClassGetParentClass();
        // Thin AOT: unbound getModifiers → NULL (#34077); VM has ReflectionClassGetModifiers (#18335).
        $this->functionProxies['reflectionclass::getmodifiers'] = new Call\ReflectionClassGetModifiers();
        // Thin AOT: unbound newInstanceWithoutConstructor → abort rc=134 (#34078); VM #5443.
        $this->functionProxies['reflectionclass::newinstancewithoutconstructor'] = new Call\ReflectionClassNewInstanceWithoutConstructor();
        // Thin AOT: unbound newInstance → abort rc=134 (#34083); VM #22086.
        $this->functionProxies['reflectionclass::newinstance'] = new Call\ReflectionClassNewInstance();
        // Thin AOT: unbound newInstanceArgs → NULL (#34090); VM #22086.
        $this->functionProxies['reflectionclass::newinstanceargs'] = new Call\ReflectionClassNewInstanceArgs();
        // Thin AOT: unbound getDefaultProperties → NULL (#34091); VM #11441 / peer get_class_vars #27229.
        $this->functionProxies['reflectionclass::getdefaultproperties'] = new Call\ReflectionClassGetDefaultProperties();
        // Thin AOT: unbound getMethods → NULL (#34107); VM #3815.
        $this->functionProxies['reflectionclass::getmethods'] = new Call\ReflectionClassGetMethods();
        // Thin AOT: unbound getProperties → NULL (#34113); VM #3815.
        $this->functionProxies['reflectionclass::getproperties'] = new Call\ReflectionClassGetProperties();
        // Thin AOT: unbound getStaticProperties → NULL (#34118); VM #6948.
        $this->functionProxies['reflectionclass::getstaticproperties'] = new Call\ReflectionClassGetStaticProperties();
        // Thin AOT: unbound getStaticPropertyValue → NULL (#34125); VM #6948 / peer getConstant #34093.
        $this->functionProxies['reflectionclass::getstaticpropertyvalue'] = new Call\ReflectionClassGetStaticPropertyValue();
        // Thin AOT: unbound setStaticPropertyValue → silent no-op (#34130); VM #6948.
        $this->functionProxies['reflectionclass::setstaticpropertyvalue'] = new Call\ReflectionClassSetStaticPropertyValue();
        // Thin AOT: unbound getExtensionName → NULL (#34139); VM #7358 / peer getFileName #34096.
        $this->functionProxies['reflectionclass::getextensionname'] = new Call\ReflectionClassGetExtensionName();
        // Thin AOT: unbound getExtension → NULL (#34145); VM #11462 / peer #34139.
        $this->functionProxies['reflectionclass::getextension'] = new Call\ReflectionClassGetExtension();
        if (CompilerVersion::supportsLazyObjectFactories()) {
            $this->functionProxies['reflectionclass::newlazyproxy'] = new Call\ReflectionClassNewLazyProxy();
            $this->functionProxies['reflectionclass::newlazyghost'] = new Call\ReflectionClassNewLazyGhost();
            // ReflectionClass::createLazyGhost/Proxy are phantoms vs php-src (#28516).
        }
        $this->functionProxies['reflectionproperty::__construct'] = new Call\ReflectionPropertyConstruct();
        $this->functionProxies['reflectionproperty::getname'] = new Call\ReflectionPropertyGetName();
        $this->functionProxies['reflectionparameter::__construct'] = new Call\ReflectionParameterConstruct();
        $this->functionProxies['reflectionparameter::getname'] = new Call\ReflectionParameterGetName();
        $this->functionProxies['reflectionparameter::gettype'] = new Call\ReflectionParameterGetType();
        $this->functionProxies['reflectionparameter::hastype'] = new Call\ReflectionParameterHasType();
        $this->functionProxies['reflectionparameter::allowsnull'] = new Call\ReflectionParameterAllowsNull();
        $this->functionProxies['reflectionparameter::isdefaultvalueavailable'] = new Call\ReflectionParameterIsDefaultValueAvailable();
        $this->functionProxies['reflectionparameter::getdefaultvalue'] = new Call\ReflectionParameterGetDefaultValue();
        $this->functionProxies['reflectionproperty::getattributes'] = new Call\ReflectionPropertyGetAttributes();
        // Thin AOT: isFinal used broken strcasecmp → true for every prop when table non-empty (#34047).
        $this->functionProxies['reflectionproperty::isfinal'] = new Call\ReflectionPropertyIsFinal();
        $this->functionProxies['reflectionproperty::isvirtual'] = new Call\ReflectionPropertyIsVirtual();
        $this->functionProxies['reflectionproperty::getrawvalue'] = new Call\ReflectionPropertyGetRawValue();
        $this->functionProxies['reflectionproperty::setrawvalue'] = new Call\ReflectionPropertySetRawValue();
        // Thin AOT: avoid undefined setaccessible / null invoke (#30910).
        $this->functionProxies['reflectionproperty::setaccessible'] = new Call\ReflectionSetAccessible('ReflectionProperty');
        $this->functionProxies['reflectionproperty::getvalue'] = new Call\ReflectionPropertyGetValue();
        $this->functionProxies['reflectionproperty::setvalue'] = new Call\ReflectionPropertySetValue();
        // Thin AOT: getDeclaringClass without proxy → ReflectionClass $name unset → SIGSEGV (#34020).
        $this->functionProxies['reflectionproperty::getdeclaringclass'] = new Call\ReflectionGetDeclaringClass(
            'ReflectionProperty',
            \PHPCompiler\VM\ReflectionSupport::PROP_DECLARING_CLASS_NAME,
            'ReflectionProperty::getDeclaringClass'
        );
        // Thin AOT: unset $class/$name → SIGSEGV on property read / getAttributes (#33990).
        $this->functionProxies['reflectionmethod::__construct'] = new Call\ReflectionMethodConstruct();
        // getName was still unbound after #33994 — silent empty string (#33990 done-when).
        $this->functionProxies['reflectionmethod::getname'] = new Call\ReflectionMethodGetName();
        // Thin AOT: getDeclaringClass without proxy → ReflectionClass $name unset → SIGSEGV (#34020).
        $this->functionProxies['reflectionmethod::getdeclaringclass'] = new Call\ReflectionGetDeclaringClass(
            'ReflectionMethod',
            \PHPCompiler\VM\ReflectionSupport::PROP_REFLECTION_METHOD_CLASS,
            'ReflectionMethod::getDeclaringClass'
        );
        $this->functionProxies['reflectionmethod::setaccessible'] = new Call\ReflectionSetAccessible('ReflectionMethod');
        $this->functionProxies['reflectionmethod::invoke'] = new Call\ReflectionMethodInvoke();
        // Thin AOT: unbound isPublic/isStatic/param counts → NULL (#34216).
        $this->functionProxies['reflectionmethod::ispublic'] = new Call\ReflectionMethodIsPublic();
        $this->functionProxies['reflectionmethod::isstatic'] = new Call\ReflectionMethodIsStatic();
        $this->functionProxies['reflectionmethod::getnumberofparameters'] = new Call\ReflectionMethodGetNumberOfParameters();
        $this->functionProxies['reflectionmethod::getnumberofrequiredparameters'] = new Call\ReflectionMethodGetNumberOfRequiredParameters();
        // Thin AOT: unbound hasReturnType blocks Nyholm StreamTrait top-level guard (#36382).
        $this->functionProxies['reflectionmethod::hasreturntype'] = new Call\ReflectionMethodHasReturnType();
        $this->functionProxies['reflectionclassconstant::__construct'] = new Call\ReflectionClassConstantConstruct();
        $this->functionProxies['reflectionclassconstant::getname'] = new Call\ReflectionClassConstantGetName();
        if (CompilerVersion::supportsReflectionPropertyGetMangledName()) {
            $this->functionProxies['reflectionproperty::getmangledname'] = new Call\ReflectionPropertyGetMangledName();
        }

        $this->functionProxies['reflectionconstant::__construct'] = new Call\ReflectionConstantConstruct();
        $this->functionProxies['reflectionconstant::getname'] = new Call\ReflectionConstantGetName();
        $this->functionProxies['reflectionconstant::getvalue'] = new Call\ReflectionConstantGetValue();
        // PHP 8.5+ only — withhold on ≤8.4 profiles (#28157).
        if (CompilerVersion::advertisesReflectionConstantGetAttributes()) {
            $this->functionProxies['reflectionconstant::getattributes'] = new Call\ReflectionConstantGetAttributes();
        }
        // ReflectionClassConstant::$class+$name layout — not ReflectionConstant::$name+$constant (#25963).
        $this->functionProxies['reflectionclassconstant::getattributes'] = new Call\ReflectionClassConstantGetAttributes();
        $this->functionProxies['reflectionmethod::getattributes'] = new Call\ReflectionMethodGetAttributes();
        $this->functionProxies['reflectionfunction::__construct'] = new Call\ReflectionFunctionConstruct();
        $this->functionProxies['reflectionfunction::getname'] = new Call\ReflectionFunctionGetName();
        // Thin AOT: unset extension name → empty getName() (#34003).
        $this->functionProxies['reflectionextension::__construct'] = new Call\ReflectionExtensionConstruct();
        $this->functionProxies['reflectionextension::getname'] = new Call\ReflectionExtensionGetName();
        // Thin AOT: unbound getVersion → NULL (#34016); VM uses VmReflection::reflectionExtensionVersion.
        $this->functionProxies['reflectionextension::getversion'] = new Call\ReflectionExtensionGetVersion();
        // Thin AOT: unbound getClassNames → NULL (#34150); VM #22247 / peer name-list #34110.
        $this->functionProxies['reflectionextension::getclassnames'] = new Call\ReflectionExtensionGetClassNames();
        // Thin AOT: unbound getClasses → NULL (#34169); VM #18326 / peer getClassNames #34150.
        $this->functionProxies['reflectionextension::getclasses'] = new Call\ReflectionExtensionGetClasses();
        // Thin AOT: unbound getFunctions → NULL (#34177); VM #18326 / peer getClasses #34169.
        $this->functionProxies['reflectionextension::getfunctions'] = new Call\ReflectionExtensionGetFunctions();
        // Thin AOT: unbound isPersistent/isTemporary → NULL (#34154); VM #22247.
        $this->functionProxies['reflectionextension::ispersistent'] = new Call\ReflectionExtensionIsPersistent();
        $this->functionProxies['reflectionextension::istemporary'] = new Call\ReflectionExtensionIsTemporary();
        // Thin AOT: unbound getDependencies → NULL (#34155); VM #22247 / peer getClassNames #34150.
        $this->functionProxies['reflectionextension::getdependencies'] = new Call\ReflectionExtensionGetDependencies();
        // Thin AOT: unbound getConstants → NULL (#34162); VM #18326 / peer getDependencies #34155.
        $this->functionProxies['reflectionextension::getconstants'] = new Call\ReflectionExtensionGetConstants();
        // Thin AOT: unbound getINIEntries → NULL (#34165); VM #22247 / peer getConstants #34162.
        $this->functionProxies['reflectionextension::getinientries'] = new Call\ReflectionExtensionGetINIEntries();
        // Thin AOT: unbound __toString/info → cast fatal / empty info (#34181); VM #22247.
        $this->functionProxies['reflectionextension::__tostring'] = new Call\ReflectionExtensionToString();
        $this->functionProxies['reflectionextension::info'] = new Call\ReflectionExtensionInfo();

        $this->functionProxies['reflectionfunction::isvariadic'] = new Call\ReflectionFunctionIsVariadic();
        // Thin AOT: unbound getNumberOfParameters / isUserDefined / isInternal → NULL (#34218).
        $this->functionProxies['reflectionfunction::getnumberofparameters'] = new Call\ReflectionFunctionGetNumberOfParameters();
        $this->functionProxies['reflectionfunction::getnumberofrequiredparameters'] = new Call\ReflectionFunctionGetNumberOfRequiredParameters();
        $this->functionProxies['reflectionfunction::getparameters'] = new Call\ReflectionFunctionGetParameters();
        $this->functionProxies['reflectionfunction::getreturntype'] = new Call\ReflectionFunctionGetReturnType();
        $this->functionProxies['reflectionfunction::hasreturntype'] = new Call\ReflectionFunctionHasReturnType();
        $this->functionProxies['reflectionfunction::isuserdefined'] = new Call\ReflectionFunctionIsUserDefined();
        $this->functionProxies['reflectionfunction::isinternal'] = new Call\ReflectionFunctionIsInternal();
        if (CompilerVersion::supportsReflectionParameterIsSensitiveParameter()) {
            $this->functionProxies['reflectionparameter::issensitiveparameter'] = new Call\ReflectionParameterIsSensitiveParameter();
        }
        $this->functionProxies['reflectionparameter::isvariadic'] = new Call\ReflectionParameterIsVariadic();
        $this->functionProxies['reflectionparameter::isoptional'] = new Call\ReflectionParameterIsOptional();
        if (CompilerVersion::supportsReflectionFunctionGetNamedArguments()) {
            $this->functionProxies['reflectionfunction::getnamedarguments'] = new Call\ReflectionFunctionGetNamedArguments();
            $this->functionProxies['reflectionmethod::getnamedarguments'] = new Call\ReflectionMethodGetNamedArguments();
        }
        $this->functionProxies['reflectionattribute::getname'] = new Call\ReflectionAttributeGetName();
        $this->functionProxies['reflectionattribute::gettarget'] = new Call\ReflectionAttributeGetTarget();
        $this->functionProxies['reflectionattribute::newinstance'] = new Call\ReflectionAttributeNewInstance();
        $this->functionProxies['reflectionenum::__construct'] = new Call\ReflectionEnumConstruct();
        $this->functionProxies['reflectionenum::getname'] = new Call\ReflectionEnumGetName();
        $this->functionProxies['reflectionenum::hascase'] = new Call\ReflectionEnumHasCase();
        $this->functionProxies['reflectionenum::getcase'] = new Call\ReflectionEnumGetCase();
        $this->functionProxies['reflectionenum::getcases'] = new Call\ReflectionEnumGetCases();
        $this->functionProxies['reflectionenum::isbacked'] = new Call\ReflectionEnumIsBacked();
        $this->functionProxies['reflectionenum::getbackingtype'] = new Call\ReflectionEnumGetBackingType();
        $this->functionProxies['reflectionenumunitcase::getname'] = new Call\ReflectionEnumUnitCaseGetName();
        $this->functionProxies['reflectionenumbackedcase::getname'] = new Call\ReflectionEnumUnitCaseGetName();
        $unitCaseGetValue = new Call\ReflectionEnumUnitCaseGetValue();
        $this->functionProxies['reflectionenumunitcase::getvalue'] = $unitCaseGetValue;
        $this->functionProxies['reflectionenumbackedcase::getvalue'] = $unitCaseGetValue;
        $this->functionProxies['reflectionnamedtype::getname'] = new Call\ReflectionNamedTypeGetName();
        $this->functionProxies['reflectionnamedtype::__tostring'] = new Call\ReflectionNamedTypeToString();
        $this->functionProxies['reflectionuniontype::__tostring'] = new Call\ReflectionUnionTypeToString();
        $this->functionProxies['exception::getmessage'] = new Call\ExceptionGetMessage('Exception');
        $this->functionProxies['exception::getcode'] = new Call\ExceptionGetCode('Exception');
        $exceptionToString = new Call\ExceptionToString();
        $exceptionGetTrace = new Call\ExceptionGetTrace('Exception');
        $exceptionGetTraceAsString = new Call\ExceptionGetTraceAsString('Exception');
        $exceptionGetFile = new Call\ExceptionGetFile('Exception');
        $exceptionGetLine = new Call\ExceptionGetLine('Exception');
        $exceptionGetPrevious = new Call\ExceptionGetPrevious('Exception');
        $this->functionProxies['exception::__tostring'] = $exceptionToString;
        $this->functionProxies['exception::gettrace'] = $exceptionGetTrace;
        $this->functionProxies['exception::gettraceasstring'] = $exceptionGetTraceAsString;
        $this->functionProxies['exception::getfile'] = $exceptionGetFile;
        $this->functionProxies['exception::getline'] = $exceptionGetLine;
        $this->functionProxies['exception::getprevious'] = $exceptionGetPrevious;
        // catch (Throwable $e) resolves methods on the interface name (#27333).
        $this->functionProxies['throwable::__tostring'] = $exceptionToString;
        $this->functionProxies['throwable::gettrace'] = $exceptionGetTrace;
        $this->functionProxies['throwable::gettraceasstring'] = $exceptionGetTraceAsString;
        $this->functionProxies['throwable::getmessage'] = $this->functionProxies['exception::getmessage'];
        $this->functionProxies['throwable::getcode'] = $this->functionProxies['exception::getcode'];
        $this->functionProxies['throwable::getfile'] = $exceptionGetFile;
        $this->functionProxies['throwable::getline'] = $exceptionGetLine;
        $this->functionProxies['throwable::getprevious'] = $exceptionGetPrevious;
        // Per-class ctor so TypeError wire text + $previous arg index match Zend (#28798).
        foreach (\PHPCompiler\ext\standard\ThrowableManifest::registrationOrder() as $throwableName) {
            if (!\PHPCompiler\ext\standard\ThrowableManifest::isAdvertised($throwableName)) {
                continue;
            }
            $lc = \PHPCompiler\ext\standard\ThrowableManifest::lcKey($throwableName);
            // ErrorException::__construct(..., $previous) is Argument #6; others #3.
            $prevArg = 'errorexception' === $lc ? 6 : 3;
            $this->functionProxies[$lc.'::__construct'] = new Call\ExceptionConstruct(
                $throwableName,
                $prevArg
            );
            $isErrorFamily = \PHPCompiler\ext\standard\ThrowableManifest::LC_ERROR === $lc
                || \PHPCompiler\ext\standard\ThrowableManifest::isDescendantOf(
                    $lc,
                    \PHPCompiler\ext\standard\ThrowableManifest::LC_ERROR
                );
            // Throwable::__toString / getTrace / get* — user-script AOT (#26796, #27333, #30895).
            $this->functionProxies[$lc.'::__tostring'] = $exceptionToString;
            $this->functionProxies[$lc.'::gettrace'] = $isErrorFamily
                ? new Call\ExceptionGetTrace('Error')
                : $exceptionGetTrace;
            $this->functionProxies[$lc.'::gettraceasstring'] = $isErrorFamily
                ? new Call\ExceptionGetTraceAsString('Error')
                : $exceptionGetTraceAsString;
            $this->functionProxies[$lc.'::getmessage'] = $isErrorFamily
                ? new Call\ExceptionGetMessage('Error')
                : $this->functionProxies['exception::getmessage'];
            $this->functionProxies[$lc.'::getcode'] = $isErrorFamily
                ? new Call\ExceptionGetCode('Error')
                : $this->functionProxies['exception::getcode'];
            $this->functionProxies[$lc.'::getfile'] = $isErrorFamily
                ? new Call\ExceptionGetFile('Error')
                : $exceptionGetFile;
            $this->functionProxies[$lc.'::getline'] = $isErrorFamily
                ? new Call\ExceptionGetLine('Error')
                : $exceptionGetLine;
            $this->functionProxies[$lc.'::getprevious'] = $isErrorFamily
                ? new Call\ExceptionGetPrevious('Error')
                : $exceptionGetPrevious;
        }
        // Alias get* for Error family roots (same prop layout; Error ACE label #30895).
        $this->functionProxies['error::getmessage'] = new Call\ExceptionGetMessage('Error');
        $this->functionProxies['error::getcode'] = new Call\ExceptionGetCode('Error');
        $this->functionProxies['error::__tostring'] = $exceptionToString;
        $this->functionProxies['error::gettrace'] = new Call\ExceptionGetTrace('Error');
        $this->functionProxies['error::gettraceasstring'] = new Call\ExceptionGetTraceAsString('Error');
        $this->functionProxies['error::getfile'] = new Call\ExceptionGetFile('Error');
        $this->functionProxies['error::getline'] = new Call\ExceptionGetLine('Error');
        $this->functionProxies['error::getprevious'] = new Call\ExceptionGetPrevious('Error');
    }
}
