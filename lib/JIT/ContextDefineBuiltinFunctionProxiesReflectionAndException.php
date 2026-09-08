<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\CompilerVersion;

/**
 * ReflectionClass* thin-AOT Call proxies for {@see Context} (#36387).
 *
 * Extracted from {@see ContextDefineBuiltinFunctionProxies} so the ReflectionClass
 * catalog stays a separate TU from member/extension/type proxies
 * ({@see ContextDefineBuiltinFunctionProxiesReflectionMembers}), Exception/Error
 * ({@see ContextDefineBuiltinFunctionProxiesExceptionAndError}), and the SPL
 * iterator / SplFile* proxy wiring (split-TU / size-budget ratchet toward
 * ReflectionAndException ≤ 200 lines, #36199 / #36403).
 *
 * Used via {@code use ContextDefineBuiltinFunctionProxiesReflectionAndException;} on
 * {@see Context}. Invoked from {@see ContextDefineBuiltinFunctionProxies::defineBuiltinFunctionProxies}
 * after WeakAndPhpToken and before ReflectionMembers / ExceptionAndError /
 * Fiber / Generator / ClosureBind helpers.
 *
 * No new C ABI. php-src analogy: zim_ReflectionClass_* method tables live in
 * ext/reflection/php_reflection.c beside the executor rather than inside a
 * monolithic MINIT catalog.
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
    }
}
