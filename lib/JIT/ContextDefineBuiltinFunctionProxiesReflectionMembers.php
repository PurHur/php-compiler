<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\CompilerVersion;

/**
 * ReflectionProperty / Method / Parameter / Function / Extension / Attribute /
 * Enum / type thin-AOT Call proxies for {@see Context} (#36387).
 *
 * Extracted from {@see ContextDefineBuiltinFunctionProxiesReflectionAndException}
 * so member/extension/type catalogs stay a separate TU from ReflectionClass*
 * wiring (split-TU / size-budget ratchet toward ReflectionAndException ≤ 200,
 * #36199 / #36403).
 *
 * Used via {@code use ContextDefineBuiltinFunctionProxiesReflectionMembers;} on
 * {@see Context}. Invoked from {@see ContextDefineBuiltinFunctionProxies::defineBuiltinFunctionProxies}
 * after ReflectionAndException (ReflectionClass*), before ExceptionAndError.
 *
 * No new C ABI. php-src analogy: zim_ReflectionProperty_* / zim_ReflectionMethod_*
 * / zim_ReflectionExtension_* live in ext/reflection/php_reflection.c beside the
 * executor rather than inside a monolithic ReflectionClass MINIT catalog.
 */
trait ContextDefineBuiltinFunctionProxiesReflectionMembers
{
    private function defineBuiltinFunctionProxiesReflectionMembers(): void
    {
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
    }
}
