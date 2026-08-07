<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Block;
use PHPCompiler\BuiltinByRefParams;
use PHPCompiler\BuiltinInternalArgInfo;
use PHPCompiler\BuiltinInternalDefaultValues;
use PHPCompiler\BuiltinParamNames;
use PHPCompiler\CompilerVersion;
use PHPCompiler\Compiler\AttributeClassRegistry;
use PHPCompiler\Compiler\AttributeEntry;
use PHPCompiler\Compiler\AttributeNames;
use PHPCompiler\Compiler\AttributeTargetValidator;
use PHPCompiler\Compiler\DeprecatedMetadata;
use PHPCompiler\Compiler\CompileTimeEnumCase;
use PHPCompiler\Compiler\CompileTimeNew;
use PHPCompiler\Compiler\ParameterMetadata;
use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\ext\standard\VmClosureCall;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\Func;
use PHPCompiler\MethodVisibility;
use PHPCompiler\Func\PHP as PhpFunc;
use PHPCompiler\OpCode;
use PHPCfg\Op\Type as CfgType;
use PHPCompiler\VM as VmEngine;
use PHPCompiler\VM\Builtin\AttributeConstruct;
use PHPCompiler\VM\Builtin\DeprecatedConstruct;
use PHPCompiler\VM\Builtin\EnumCasesConstruct;
use PHPCompiler\VM\Builtin\NoDiscardConstruct;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectHandleSupport;
use PHPCompiler\VM\Variable;

/**
 * Build ReflectionAttribute stubs from compile-time metadata (#1936, #3206, #3340, #3800).
 */
final class ReflectionSupport
{
    /** php-src class Reflection — getModifierNames() utility (#22127). */
    public const REFLECTION = 'reflection';

    public const REFLECTION_CLASS = 'reflectionclass';

    /** php-src: class ReflectionObject extends ReflectionClass (#20098). */
    public const REFLECTION_OBJECT = 'reflectionobject';

    public const REFLECTION_METHOD = 'reflectionmethod';

    public const REFLECTION_PROPERTY = 'reflectionproperty';

    public const REFLECTION_FUNCTION = 'reflectionfunction';

    public const REFLECTION_FUNCTION_ABSTRACT = 'reflectionfunctionabstract';

    public const REFLECTION_CONSTANT = 'reflectionconstant';

    public const REFLECTION_CLASS_CONSTANT = 'reflectionclassconstant';

    public const REFLECTION_ATTRIBUTE = 'reflectionattribute';

    public const REFLECTION_EXTENSION = 'reflectionextension';

    /** php-src class ReflectionZendExtension (#22248). */
    public const REFLECTION_ZEND_EXTENSION = 'reflectionzendextension';

    /** php-src REFLECTION_ATTRIBUTE_IS_INSTANCEOF — getAttributes() filter flag (#11471). */
    public const REFLECTION_ATTRIBUTE_IS_INSTANCEOF = 2;

    public const REFLECTION_ENUM = 'reflectionenum';

    public const REFLECTION_ENUM_UNIT_CASE = 'reflectionenumunitcase';

    public const REFLECTION_ENUM_BACKED_CASE = 'reflectionenumbackedcase';

    public const REFLECTION_PARAMETER = 'reflectionparameter';

    public const REFLECTION_TYPE = 'reflectiontype';

    public const REFLECTION_NAMED_TYPE = 'reflectionnamedtype';

    public const REFLECTION_UNION_TYPE = 'reflectionuniontype';

    public const REFLECTION_INTERSECTION_TYPE = 'reflectionintersectiontype';

    public const REFLECTION_FIBER = 'reflectionfiber';

    public const REFLECTION_GENERATOR = 'reflectiongenerator';

    public const REFLECTION_REFERENCE = 'reflectionreference';

    /** Opaque getId() payload on ReflectionReference instances (#22065). */
    public const PROP_REFLECTION_REFERENCE_ID = 'referenceId';

    public const PROP_CLASS_NAME = 'name';

    public const PROP_METHOD_NAME = 'method';

    /** Zend ReflectionMethod::$class — declaring class name string (#18298). */
    public const PROP_REFLECTION_METHOD_CLASS = 'class';

    /** Zend ReflectionMethod::$name — method name string (#18298). */
    public const PROP_REFLECTION_METHOD_FUNC = 'name';

    /**
     * Zend ReflectionProperty::$name — property name string (#22504).
     * Was engine-only key `property` (leaked in dumps; conflicted with public `$name` = class).
     */
    public const PROP_PROPERTY_NAME = 'name';

    /**
     * Zend ReflectionProperty::$class — declaring class name string (#22504, #9878).
     * Was engine-only key `declaringClass`.
     */
    public const PROP_DECLARING_CLASS_NAME = 'class';

    /**
     * Declaring class on ReflectionParameter (engine storage only).
     * Zend public surface is only `$name` (parameter name); do not reuse PROP_CLASS_NAME (#22528).
     */
    public const PROP_PARAM_CLASS = 'paramClass';

    /** Runtime dynamic property introspection (#15540, ext/reflection/php_reflection.c). */
    public const PROP_IS_DYNAMIC = 'isDynamicFlag';

    /** setAccessible() override — php-src ref->accessible (#9823). */
    public const PROP_ACCESSIBLE = 'accessible';

    public const PROP_FUNCTION_NAME = 'function';

    /**
     * Engine storage for ReflectionConstant (global) constant name (#17341).
     * Not the Zend dump key for ReflectionClassConstant — use PROP_REFLECTION_CLASS_CONSTANT_NAME (#22503).
     */
    public const PROP_CONSTANT_NAME = 'constant';

    /** Zend ReflectionClassConstant::$class — declaring class name string (#22503). */
    public const PROP_REFLECTION_CLASS_CONSTANT_CLASS = 'class';

    /** Zend ReflectionClassConstant::$name — constant name string (#22503). */
    public const PROP_REFLECTION_CLASS_CONSTANT_NAME = 'name';

    public const PROP_ATTR_NAME = 'name';

    /** Serialized attribute ctor args on ReflectionAttribute instances (#3206). */
    public const PROP_ATTR_ARGS = 'args';

    /** Whether this attribute name is duplicated on the target (#6912). */
    public const PROP_ATTR_IS_REPEATED = 'isRepeated';

    /** Attribute::TARGET_* bitmask for the declaration site (#22044). */
    public const PROP_ATTR_TARGET = 'target';

    /** Delayed #[\DelayedTargetValidation] error text from compile (#26241). */
    public const PROP_ATTR_VALIDATION_ERROR = 'validationError';

    public const PROP_EXTENSION_NAME = 'extension';

    /** Public `$name` on ReflectionZendExtension (php-src, #22248). */
    public const PROP_ZEND_EXTENSION_NAME = 'name';

    /**
     * Zend ReflectionEnumUnitCase / ReflectionEnumBackedCase::$class — enum class name (#22505).
     * Same public key as ReflectionClassConstant::$class (php-src php_reflection.c).
     * Was internal-only `enumClass` (#10000), which leaked in dumps / property_exists.
     */
    public const PROP_ENUM_CLASS_NAME = 'class';

    /** @deprecated Use PROP_CLASS_NAME (`name`) for case name + PROP_ENUM_CLASS_NAME (`class`) for enum type. */
    public const PROP_ENUM_CASE_NAME = 'case';

    /**
     * Internal function name on ReflectionParameter (phpInvisible).
     * Not the Zend public dump key for ReflectionFunction — use PROP_REFLECTION_FUNCTION_NAME (#22488).
     */
    public const PROP_FUNC_NAME = 'funcName';

    /** Zend ReflectionFunction::$name — function/closure display name (#22488). */
    public const PROP_REFLECTION_FUNCTION_NAME = 'name';

    /** Wrapped Fiber object on ReflectionFiber instances (#6793). */
    public const PROP_FIBER_TARGET = 'fiber';

    /** Wrapped Generator object on ReflectionGenerator instances (#5964). */
    public const PROP_GENERATOR_TARGET = 'generator';

    /** Wrapped instance on ReflectionObject — dynamic props / getProperties (#20098). */
    public const PROP_OBJECT_TARGET = 'object';

    public const PROP_PARAM_INDEX = 'paramIndex';

    /** Zend ReflectionParameter::$name — parameter name string (#22528, re-#22488). */
    public const PROP_PARAM_NAME = 'name';

    public const PROP_PARAM_POSITION = 'position';

    public const PROP_TYPE_NAME = 'typeName';

    public const PROP_TYPE_BUILTIN = 'typeBuiltin';

    public const PROP_TYPE_STRING = 'typeString';

    public const PROP_TYPE_ALLOWS_NULL = 'allowsNullFlag';

    public const PROP_TYPE_MEMBERS = 'typeMembers';

    /** php-src: ext/reflection/php_reflection.c — class/member lookup failures (#7344). */
    public static function classNotFoundMessage(string $className): string
    {
        return sprintf('Class "%s" does not exist', $className);
    }

    public static function classNotEnumMessage(string $className): string
    {
        return sprintf('Class "%s" is not an enum', $className);
    }

    public static function methodNotFoundMessage(string $className, string $method): string
    {
        return sprintf('Method %s::%s() does not exist', $className, $method);
    }

    public static function propertyNotFoundMessage(string $className, string $property): string
    {
        return sprintf('Property %s::$%s does not exist', $className, $property);
    }

    public static function constantNotFoundMessage(string $className, string $constant): string
    {
        return sprintf('Constant %s::%s does not exist', $className, $constant);
    }

    public static function globalConstantNotFoundMessage(string $constant): string
    {
        return sprintf('Constant "%s" does not exist', $constant);
    }

    public static function isGlobalReflectionConstant(ObjectEntry $reflection): bool
    {
        $classVar = $reflection->getProperty(self::PROP_CLASS_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $classVar->type) {
            return false;
        }

        return '' === $classVar->toString();
    }

    public static function functionNotFoundMessage(string $functionName): string
    {
        return sprintf('Function %s() does not exist', $functionName);
    }

    public static function enumCaseNotFoundMessage(string $enumName, string $caseName): string
    {
        return 'Case '.$enumName.'::'.$caseName.' does not exist';
    }

    /** @return never */
    public static function throwReflectionException(string $message): void
    {
        throw new \ReflectionException($message);
    }

    /**
     * Zend-shaped ArgumentCountError for Reflection*::__construct too-few/wrong argc (#22739).
     *
     * php-src: ext/reflection/php_reflection.c — ZEND_PARSE_PARAMETERS → ArgumentCountError
     *
     * @param 'exactly'|'at least'|'at most' $phrase
     */
    public static function throwConstructArgumentCountError(
        string $className,
        int $expected,
        int $given,
        string $phrase = 'exactly'
    ): void {
        $noun = 1 === $expected ? 'argument' : 'arguments';

        throw new \ArgumentCountError(sprintf(
            '%s::__construct() expects %s %d %s, %d given',
            $className,
            $phrase,
            $expected,
            $noun,
            $given
        ));
    }

    /**
     * @param list<string> $names
     */
    public static function attributesArray(Frame $frame, array $names, int $target = 0): Variable
    {
        $entries = [];
        foreach ($names as $name) {
            $entries[] = new AttributeEntry($name);
        }

        return self::attributesArrayFromEntries($frame, $entries, $target);
    }

    /**
     * @param list<AttributeEntry> $entries
     * @param int                  $target  Attribute::TARGET_* for the declaration site (#22044)
     */
    public static function attributesArrayFromEntries(Frame $frame, array $entries, int $target = 0): Variable
    {
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('Reflection requires VM context');
        }
        $attrClass = $ctx->classes[self::REFLECTION_ATTRIBUTE] ?? null;
        if (null === $attrClass) {
            throw new \LogicException('ReflectionAttribute is not registered in this compiler build');
        }
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach ($entries as $entry) {
            if (!$entry instanceof AttributeEntry) {
                continue;
            }
            $obj = new ObjectEntry($attrClass);
            $obj->constructed = true;
            $obj->getProperty(self::PROP_ATTR_NAME)->string($entry->name);
            $obj->getProperty(self::PROP_ATTR_ARGS)->copyFrom(self::argsToVariable($entry->args, $ctx));
            $obj->getProperty(self::PROP_ATTR_IS_REPEATED)->bool($entry->isRepeated);
            $obj->getProperty(self::PROP_ATTR_TARGET)->int($target);
            $obj->getProperty(self::PROP_ATTR_VALIDATION_ERROR)->string(
                null !== $entry->validationError ? $entry->validationError : ''
            );
            $slot = new Variable(Variable::TYPE_OBJECT);
            $slot->object($obj);
            $ht->append($slot);
        }

        return $result;
    }

    /**
     * @param list<array{name: ?string, value: mixed}> $args
     */
    public static function argsToVariable(array $args, ?Context $ctx = null): Variable
    {
        $arr = new Variable();
        $arr->newArray();
        $ht = $arr->toArray();
        foreach ($args as $spec) {
            $entry = new Variable();
            $entry->newArray();
            $entryHt = $entry->toArray();
            $nameVal = new Variable();
            if (null === $spec['name']) {
                $nameVal->null();
            } else {
                $nameVal->string($spec['name']);
            }
            $entryHt->add('name', $nameVal);
            $entryHt->add('value', self::attributeValueToVariable($spec['value'], $ctx));
            $ht->append($entry);
        }

        return $arr;
    }

    /**
     * Build PHP-style getArguments() array from stored ctor arg specs.
     *
     * @param list<array{name: ?string, value: mixed}> $args
     */
    public static function argumentsArray(array $args, ?Context $ctx = null): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach ($args as $spec) {
            $val = self::attributeValueToVariable($spec['value'], $ctx);
            if (null !== $spec['name']) {
                $ht->add($spec['name'], $val);
            } else {
                $ht->append($val);
            }
        }

        return $result;
    }

    public static function attributeValueToVariable(mixed $value, ?Context $ctx = null): Variable
    {
        if ($value instanceof CompileTimeNew) {
            if (null === $ctx) {
                throw new \LogicException(
                    'Compile-time new in attribute args requires VM context to materialize'
                );
            }

            return self::materializeCompileTimeNew($ctx, $value);
        }
        if ($value instanceof CompileTimeEnumCase) {
            if (null === $ctx) {
                throw new \LogicException(
                    'Compile-time enum case in attribute args requires VM context to materialize'
                );
            }

            return self::materializeCompileTimeEnumCase($ctx, $value);
        }
        if ($value instanceof Variable) {
            $copy = new Variable();
            $copy->copyFrom($value);

            return $copy;
        }
        if (\is_array($value)) {
            return self::phpArrayToVariable($value, $ctx);
        }

        return self::scalarToVariable($value);
    }

    /**
     * Materialize a PHP array from attribute const-eval (nested `new` / scalars; #22391).
     *
     * @param array<int|string, mixed> $value
     */
    public static function phpArrayToVariable(array $value, ?Context $ctx = null): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        if (array_is_list($value)) {
            foreach ($value as $item) {
                $ht->append(self::attributeValueToVariable($item, $ctx));
            }

            return $result;
        }
        foreach ($value as $key => $item) {
            $ht->add((string) $key, self::attributeValueToVariable($item, $ctx));
        }

        return $result;
    }

    public static function scalarToVariable(mixed $value): Variable
    {
        $var = new Variable();
        if (null === $value) {
            $var->null();
        } elseif (is_bool($value)) {
            $var->bool($value);
        } elseif (is_int($value)) {
            $var->int($value);
        } elseif (is_float($value)) {
            $var->float($value);
        } elseif (is_string($value)) {
            $var->string($value);
        } else {
            throw new \LogicException('Unsupported attribute argument type in this compiler build');
        }

        return $var;
    }

    public static function materializeCompileTimeNew(Context $ctx, CompileTimeNew $spec): Variable
    {
        $className = $spec->className;
        $lc = strtolower(ltrim($className, '\\'));
        if (!isset($ctx->classes[$lc])) {
            $ctx->autoloadClass($className);
        }
        if (!isset($ctx->classes[$lc])) {
            throw new \Error('Class "'.$className.'" not found');
        }
        $classEntry = $ctx->classes[$lc];
        if ($classEntry->isEnum) {
            throw new \Error("Cannot instantiate enum {$classEntry->name}");
        }
        if ($classEntry->isInterface) {
            throw new \Error("Cannot instantiate interface {$classEntry->name}");
        }
        if ($classEntry->isTrait) {
            throw new \Error("Cannot instantiate trait {$classEntry->name}");
        }
        if ($classEntry->isAbstract) {
            throw new \Error("Cannot instantiate abstract class {$classEntry->name}");
        }
        $object = new ObjectEntry($classEntry);
        $result = new Variable();
        $result->object($object);
        if (null === $classEntry->constructor) {
            $object->constructed = true;

            return $result;
        }
        $vm = VmEngine::running();
        if (null === $vm) {
            throw new \LogicException('Cannot materialize attribute new expression without active VM');
        }
        $thisVar = new Variable();
        $thisVar->object($object);
        $invokeArgs = self::constructorInvokeVariables($classEntry->constructor, $spec->args, $ctx);
        self::invokeAttributeConstructor($vm, $ctx, $classEntry->constructor, $thisVar, $invokeArgs);
        self::applyConstructorPropertyArgs($object, $classEntry->constructor, $spec->args, $ctx);
        $object->constructed = true;

        return $result;
    }

    public static function materializeCompileTimeEnumCase(Context $ctx, CompileTimeEnumCase $spec): Variable
    {
        $enumName = $spec->enumName;
        $lc = strtolower(ltrim($enumName, '\\'));
        if (!isset($ctx->classes[$lc])) {
            $ctx->autoloadClass($enumName);
        }
        if (!isset($ctx->classes[$lc])) {
            throw new \Error('Class "'.$enumName.'" not found');
        }
        $enum = $ctx->classes[$lc];
        if (!$enum->isEnum) {
            return self::materializeCompileTimeOrdinaryClassConst($ctx, $enum, $spec->caseName);
        }
        $result = new Variable();
        if (!EnumCaseSupport::tryMaterializeEnumCaseConstantFetch(
            $enum,
            \PHPCompiler\ClassConstName::key($spec->caseName),
            $result
        )) {
            throw new \Error(self::enumCaseNotFoundMessage($enum->name, $spec->caseName));
        }

        return $result;
    }

    /**
     * Attribute ctor args store ClassConstFetch as {@see CompileTimeEnumCase}; non-enums are ordinary constants (#19908).
     */
    private static function materializeCompileTimeOrdinaryClassConst(
        Context $ctx,
        ClassEntry $entry,
        string $constName,
    ): Variable {
        $constKey = \PHPCompiler\ClassConstName::key($constName);
        $stored = self::lookupClassConstantVariable($ctx, $entry, $constKey);
        if (null === $stored) {
            $display = $entry->constNames[$constKey] ?? $constName;
            throw new \Error("Undefined constant {$entry->name}::{$display}");
        }
        $declared = $entry->constNames[$constKey]
            ?? $entry->enumCaseCanonicalNames[$constKey]
            ?? null;
        if (null !== $declared && $declared !== $constName) {
            throw new \Error("Undefined constant {$entry->name}::{$constName}");
        }
        $result = new Variable();
        $result->copyFrom($stored);

        return $result;
    }

    private static function lookupClassConstantVariable(
        Context $ctx,
        ClassEntry $entry,
        string $constLc,
    ): ?Variable {
        if (isset($entry->constants[$constLc])) {
            return $entry->constants[$constLc];
        }
        foreach ($entry->interfaces as $ifaceLc) {
            if (!isset($ctx->classes[$ifaceLc])) {
                continue;
            }
            $fromIface = self::lookupClassConstantVariable($ctx, $ctx->classes[$ifaceLc], $constLc);
            if (null !== $fromIface) {
                return $fromIface;
            }
        }
        if (null === $entry->parentLc || !isset($ctx->classes[$entry->parentLc])) {
            return null;
        }
        $parent = $ctx->classes[$entry->parentLc];
        if (isset($parent->constants[$constLc])) {
            $vis = $parent->constVisibility[$constLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
            if (($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
                return self::lookupClassConstantVariable($ctx, $parent, $constLc);
            }

            return $parent->constants[$constLc];
        }

        return self::lookupClassConstantVariable($ctx, $parent, $constLc);
    }

    /**
     * @param array<int, Variable> $invokeArgs possibly sparse — omitted optionals use RECV defaults (#26768)
     */
    public static function invokeAttributeConstructor(
        VmEngine $vm,
        Context $ctx,
        Func $ctor,
        Variable $thisVar,
        array $invokeArgs,
    ): void {
        if ($ctor instanceof Func\PHP) {
            // Isolate the run stack: invokePhpFunctionOnStack would resume the caller's
            // caller after a void __construct return (TYPE_RETURN_VOID → nextframe), so
            // ReflectionAttribute::newInstance() from inside a user function returned early
            // with null (#22029; same pattern as #4284 / #12069).
            // ARG_RECV shifts instance method indices by +1 for $this (see VM TYPE_ARG_RECV).
            // Keep sparse keys so omitted attribute AST args hit parameter defaults (#26768;
            // php-src ext/reflection/php_reflection.c ZEND_METHOD(ReflectionAttribute, newInstance)).
            $calledArgs = [0 => $thisVar];
            foreach ($invokeArgs as $idx => $value) {
                $calledArgs[1 + (int) $idx] = $value;
            }
            $vm->invokePhpFunctionIsolatedWithCalledArgs($ctor, $calledArgs);

            return;
        }
        if ($ctor instanceof Func\Internal) {
            $frame = $ctor->getFrame($ctx);
            $frame->vmContext = $ctx;
            $calledArgs = [0 => $thisVar];
            foreach ($invokeArgs as $idx => $value) {
                $calledArgs[1 + (int) $idx] = $value;
            }
            $frame->calledArgs = $calledArgs;
            $ctor->execute($frame);

            return;
        }
        throw new \LogicException('Unsupported attribute constructor in this compiler build');
    }

    /**
     * @return list<string>
     */
    public static function constructorParamNames(Func $ctor): array
    {
        if ($ctor instanceof Func\PHP) {
            return $ctor->block->paramNames;
        }
        if ($ctor instanceof Func\Internal) {
            return match ($ctor::class) {
                DeprecatedConstruct::class => ['message', 'since'],
                NoDiscardConstruct::class => ['message'],
                EnumCasesConstruct::class => ['name'],
                AttributeConstruct::class => ['flags'],
                default => [],
            };
        }

        return [];
    }

    /**
     * Map stored attribute ctor args to invokePhpFunction arguments in parameter order (#3216).
     *
     * Only arguments present in the attribute AST are included. Omitted optional parameters
     * are left unset so TYPE_ARG_RECV applies defaults (php-src ReflectionAttribute::newInstance,
     * #26768) — do not pad with null (that TypeErrors typed defaults like `int $flags = 0`).
     *
     * @param list<array{name: ?string, value: mixed}> $argSpecs
     *
     * @return array<int, Variable> possibly sparse (named optionals / trailing defaults)
     */
    public static function constructorInvokeVariables(
        Func $ctor,
        array $argSpecs,
        ?Context $ctx = null,
    ): array {
        $positional = [];
        $named = [];
        foreach ($argSpecs as $spec) {
            if (null !== $spec['name']) {
                $named[$spec['name']] = $spec['value'];
            } else {
                $positional[] = $spec['value'];
            }
        }
        $vars = [];
        $pi = 0;
        $paramIndex = 0;
        foreach (self::constructorParamNames($ctor) as $paramName) {
            if (isset($named[$paramName])) {
                $vars[$paramIndex] = self::attributeValueToVariable($named[$paramName], $ctx);
            } elseif (array_key_exists($pi, $positional)) {
                $vars[$paramIndex] = self::attributeValueToVariable($positional[$pi++], $ctx);
            }
            // else: omit — RECV / internal ctor applies the parameter default (#26768)
            ++$paramIndex;
        }

        return $vars;
    }

    /**
     * Sync promoted / declared instance properties from attribute ctor args (#3216).
     *
     * VM ctor promotion assignments can fail when invoked from builtins; Zend sets properties in __construct.
     *
     * @param list<array{name: ?string, value: mixed}> $argSpecs
     */
    public static function applyConstructorPropertyArgs(
        ObjectEntry $object,
        Func $ctor,
        array $argSpecs,
        ?Context $ctx = null,
    ): void {
        if ($ctor instanceof Func\Internal) {
            return;
        }
        $positional = [];
        $named = [];
        foreach ($argSpecs as $spec) {
            if (null !== $spec['name']) {
                $named[$spec['name']] = $spec['value'];
            } else {
                $positional[] = $spec['value'];
            }
        }
        $pi = 0;
        foreach (self::constructorParamNames($ctor) as $paramName) {
            if (isset($named[$paramName])) {
                $value = $named[$paramName];
            } elseif (array_key_exists($pi, $positional)) {
                $value = $positional[$pi++];
            } else {
                continue;
            }
            if ($object->hasProperty($paramName)) {
                $object->getProperty($paramName)->copyFrom(self::attributeValueToVariable($value, $ctx));
            }
        }
    }

    /**
     * @return list<array{name: ?string, value: mixed}>
     */
    public static function argsFromReflectionObject(ObjectEntry $attr): array
    {
        $argsVar = $attr->getProperty(self::PROP_ATTR_ARGS)->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $argsVar->type) {
            return [];
        }
        $out = [];
        foreach ($argsVar->toArray()->iterateKeyed(true) as [, $entryVar]) {
            $entry = $entryVar->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $entry->type) {
                continue;
            }
            $name = null;
            $value = null;
            foreach ($entry->toArray()->iterateKeyed(true) as [$k, $v]) {
                $key = $k->resolveIndirect();
                if (Variable::TYPE_STRING !== $key->type) {
                    continue;
                }
                $resolved = $v->resolveIndirect();
                if ('name' === $key->toString()) {
                    $name = Variable::TYPE_NULL === $resolved->type ? null : $resolved->toString();
                } elseif ('value' === $key->toString()) {
                    $value = match ($resolved->type) {
                        Variable::TYPE_OBJECT, Variable::TYPE_ENUM_CASE, Variable::TYPE_ARRAY => $resolved,
                        default => self::variableToScalar($resolved),
                    };
                }
            }
            $out[] = ['name' => $name, 'value' => $value];
        }

        return $out;
    }

    public static function variableToScalar(Variable $var): mixed
    {
        $var = $var->resolveIndirect();

        return match ($var->type) {
            Variable::TYPE_NULL => null,
            Variable::TYPE_BOOLEAN => $var->toBool(),
            Variable::TYPE_INTEGER => $var->toInt(),
            Variable::TYPE_FLOAT => $var->toFloat(),
            Variable::TYPE_STRING => $var->toString(),
            default => throw new \LogicException('Unsupported attribute argument type in this compiler build'),
        };
    }

    public static function requireReflectionAttribute(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionAttribute method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_ATTRIBUTE) {
            throw new \LogicException('Expected ReflectionAttribute instance');
        }

        return $obj;
    }

    /**
     * ReflectionAttribute::newInstance() — reject classes without #[Attribute] (#24930).
     *
     * php-src: ext/reflection/php_reflection.c ZEND_METHOD(ReflectionAttribute, newInstance)
     * checks ce->ce_flags & ZEND_ACC_ATTRIBUTE before target / repeatable checks.
     */
    public static function assertAttributeNewInstanceIsAttributeClass(
        ObjectEntry $receiver,
        ClassEntry $attributeClass,
    ): void {
        if (AttributeClassRegistry::isRegisteredAttributeClass($attributeClass->attributeEntries)) {
            return;
        }
        $nameVar = $receiver->getProperty(self::PROP_ATTR_NAME)->resolveIndirect();
        $name = Variable::TYPE_STRING === $nameVar->type
            ? $nameVar->toString()
            : $attributeClass->name;
        $name = ltrim($name, '\\');

        throw new \Error('Attempting to use non-attribute class "'.$name.'" as attribute');
    }

    /**
     * ReflectionAttribute::newInstance() — reject wrong Attribute::TARGET_* (#23528).
     *
     * php-src: ext/reflection/php_reflection.c ZEND_METHOD(ReflectionAttribute, newInstance)
     * checks (attr->flags & target) after resolving the attribute class's #[Attribute] mask.
     * User Attribute::TARGET_* mismatches are deferred here (not compile-fatal) — #25729.
     * Caller must run {@see assertAttributeNewInstanceIsAttributeClass} first (#24930).
     */
    public static function assertAttributeNewInstanceTargetAllowed(
        ObjectEntry $receiver,
        ClassEntry $attributeClass,
    ): void {
        $flags = AttributeClassRegistry::extractSelfAttributeFlags($attributeClass->attributeEntries);
        if (null === $flags) {
            // Defensive: primary guard is assertAttributeNewInstanceIsAttributeClass (#24930).
            self::assertAttributeNewInstanceIsAttributeClass($receiver, $attributeClass);

            return;
        }
        $allowed = $flags & AttributeSupport::targetAll();
        $targetVar = $receiver->getProperty(self::PROP_ATTR_TARGET)->resolveIndirect();
        $siteTarget = Variable::TYPE_INTEGER === $targetVar->type ? $targetVar->toInt() : 0;
        if (0 === $siteTarget || 0 !== ($allowed & $siteTarget)) {
            return;
        }
        $nameVar = $receiver->getProperty(self::PROP_ATTR_NAME)->resolveIndirect();
        $name = Variable::TYPE_STRING === $nameVar->type
            ? $nameVar->toString()
            : $attributeClass->name;

        throw new \Error(AttributeTargetValidator::runtimeWrongTargetMessage($name, $siteTarget, $allowed));
    }

    /**
     * ReflectionAttribute::newInstance() — raise compile-deferred internal attribute errors (#26241).
     *
     * php-src: ext/reflection/php_reflection.c — zend_attribute.validation_error set when
     * #[\DelayedTargetValidation] suppressed a compile-time validator failure.
     */
    public static function assertAttributeNewInstanceNoDelayedValidationError(ObjectEntry $receiver): void
    {
        $errVar = $receiver->getProperty(self::PROP_ATTR_VALIDATION_ERROR)->resolveIndirect();
        if (Variable::TYPE_STRING !== $errVar->type) {
            return;
        }
        $message = $errVar->toString();
        if ('' === $message) {
            return;
        }

        throw new \Error($message);
    }

    /**
     * ReflectionAttribute::newInstance() — refuse abstract / interface / trait / enum (#26238).
     *
     * php-src: zend_get_attribute_object() → object_init_ex fails the same way as `new`
     * (ext/reflection/php_reflection.c ZEND_METHOD(ReflectionAttribute, newInstance)).
     * Call after attribute-marker / target / repeatable checks; before ObjectEntry allocate.
     */
    public static function assertAttributeNewInstanceInstantiable(ClassEntry $attributeClass): void
    {
        ClassValidator::assertInstantiable($attributeClass);
    }

    /**
     * ReflectionAttribute::newInstance() — reject non-IS_REPEATABLE user duplicates (#22930).
     *
     * php-src: ext/reflection/php_reflection.c ZEND_METHOD(ReflectionAttribute, newInstance)
     * when ce is ZEND_USER_CLASS, flags lack ZEND_ATTRIBUTE_IS_REPEATABLE, and
     * zend_is_attribute_repeated() is true. Compile still allows user duplicates (≠ #5239).
     */
    public static function assertAttributeNewInstanceNotIllegalRepeat(
        ObjectEntry $receiver,
        ClassEntry $attributeClass,
    ): void {
        if ($attributeClass->isInternal) {
            return;
        }
        $repeatedVar = $receiver->getProperty(self::PROP_ATTR_IS_REPEATED)->resolveIndirect();
        if (!$repeatedVar->toBool()) {
            return;
        }
        $flags = AttributeClassRegistry::extractSelfAttributeFlags($attributeClass->attributeEntries);
        if (null === $flags) {
            $flags = 0;
        }
        if (0 !== ($flags & AttributeSupport::isRepeatableFlag())) {
            return;
        }
        $nameVar = $receiver->getProperty(self::PROP_ATTR_NAME)->resolveIndirect();
        $name = Variable::TYPE_STRING === $nameVar->type
            ? $nameVar->toString()
            : $attributeClass->name;
        $name = ltrim($name, '\\');
        $pos = strrpos($name, '\\');
        $short = false !== $pos ? substr($name, $pos + 1) : $name;

        throw new \Error('Attribute "'.$short.'" must not be repeated');
    }

    public static function requireReflectionExtension(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionExtension method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_EXTENSION) {
            throw new \LogicException('Expected ReflectionExtension instance');
        }

        return $obj;
    }

    public static function requireReflectionZendExtension(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionZendExtension method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_ZEND_EXTENSION) {
            throw new \LogicException('Expected ReflectionZendExtension instance');
        }

        return $obj;
    }

    /**
     * ReflectionClass and subclasses (ReflectionEnum / ReflectionObject in php-src, #19740, #20098).
     */
    public static function isReflectionClassObject(ObjectEntry $obj): bool
    {
        $lc = strtolower($obj->class->name);

        return self::REFLECTION_CLASS === $lc
            || self::REFLECTION_ENUM === $lc
            || self::REFLECTION_OBJECT === $lc;
    }

    public static function requireReflectionClass(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionClass method called without object');
        }
        $obj = $receiver->toObject();
        if (!self::isReflectionClassObject($obj)) {
            throw new \LogicException('Expected ReflectionClass instance');
        }

        return $obj;
    }

    public static function requireReflectionObject(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionObject method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_OBJECT) {
            throw new \LogicException('Expected ReflectionObject instance');
        }

        return $obj;
    }

    /** Instance stored by ReflectionObject::__construct, or null. */
    public static function objectTargetFromReflectionObject(ObjectEntry $reflection): ?ObjectEntry
    {
        if (strtolower($reflection->class->name) !== self::REFLECTION_OBJECT) {
            return null;
        }
        if (!$reflection->hasProperty(self::PROP_OBJECT_TARGET)) {
            return null;
        }
        $slot = $reflection->getProperty(self::PROP_OBJECT_TARGET)->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $slot->type) {
            return null;
        }

        return $slot->toObject();
    }

    public static function requireReflectionMethod(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionMethod method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_METHOD) {
            throw new \LogicException('Expected ReflectionMethod instance');
        }

        return $obj;
    }

    public static function requireReflectionParameter(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionParameter method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_PARAMETER) {
            throw new \LogicException('Expected ReflectionParameter instance');
        }

        return $obj;
    }

    public static function requireReflectionEnum(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionEnum method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_ENUM) {
            throw new \LogicException('Expected ReflectionEnum instance');
        }

        return $obj;
    }

    public static function requireReflectionFiber(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionFiber method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_FIBER) {
            throw new \LogicException('Expected ReflectionFiber instance');
        }

        return $obj;
    }

    public static function requireReflectionGenerator(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionGenerator method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_GENERATOR) {
            throw new \LogicException('Expected ReflectionGenerator instance');
        }

        return $obj;
    }

    public static function requireReflectionReference(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionReference method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_REFERENCE) {
            throw new \LogicException('Expected ReflectionReference instance');
        }

        return $obj;
    }

    public static function reflectionFunctionFromGenerator(Context $ctx, GeneratorState $gen): ObjectEntry
    {
        if (null !== $gen->closureCall) {
            return self::reflectionFunctionFromClosureState($ctx, $gen->closureCall);
        }

        return self::reflectionFunctionFromFunctionName($ctx, $gen->func->getName());
    }

    public static function reflectionFunctionFromClosureState(Context $ctx, ClosureState $state): ObjectEntry
    {
        return self::populateReflectionFunctionFromClosureState($ctx, $state);
    }

    public static function isReflectionEnumCaseObject(ObjectEntry $obj): bool
    {
        $lc = strtolower($obj->class->name);

        return self::REFLECTION_ENUM_UNIT_CASE === $lc
            || self::REFLECTION_ENUM_BACKED_CASE === $lc;
    }

    public static function requireReflectionEnumCase(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionEnumUnitCase method called without object');
        }
        $obj = $receiver->toObject();
        if (!self::isReflectionEnumCaseObject($obj)) {
            throw new \LogicException('Expected ReflectionEnumUnitCase instance');
        }

        return $obj;
    }

    public static function requireReflectionEnumUnitCase(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionEnumUnitCase method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_ENUM_UNIT_CASE) {
            throw new \LogicException('Expected ReflectionEnumUnitCase instance');
        }

        return $obj;
    }

    public static function requireReflectionEnumBackedCase(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionEnumBackedCase method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_ENUM_BACKED_CASE) {
            throw new \LogicException('Expected ReflectionEnumBackedCase instance');
        }

        return $obj;
    }

    public static function isReflectionMethodObject(ObjectEntry $reflection): bool
    {
        return strtolower($reflection->class->name) === self::REFLECTION_METHOD;
    }

    public static function classNameFromReflection(ObjectEntry $reflection): string
    {
        // Enum case wrappers: case name on PROP_CLASS_NAME (`name`); enum on PROP_ENUM_CLASS_NAME (`class`, #22505).
        if (self::isReflectionEnumCaseObject($reflection)) {
            return self::enumClassNameFromReflection($reflection);
        }
        // ReflectionParameter: declaring class is PROP_PARAM_CLASS (not public `$name`, #22528).
        if (strtolower($reflection->class->name) === self::REFLECTION_PARAMETER) {
            $nameVar = $reflection->getProperty(self::PROP_PARAM_CLASS)->resolveIndirect();
            if (Variable::TYPE_STRING !== $nameVar->type) {
                throw new \LogicException('ReflectionParameter missing declaring class name');
            }

            return $nameVar->toString();
        }
        // ReflectionClassConstant: declaring class is public `$class` (not `$name`, #22503).
        if (strtolower($reflection->class->name) === self::REFLECTION_CLASS_CONSTANT) {
            $nameVar = $reflection->getProperty(self::PROP_REFLECTION_CLASS_CONSTANT_CLASS)->resolveIndirect();
            if (Variable::TYPE_STRING !== $nameVar->type) {
                throw new \LogicException('ReflectionClassConstant missing declaring class name');
            }

            return $nameVar->toString();
        }
        // ReflectionProperty: declaring class is public `$class` (not `$name`, #22504).
        if (strtolower($reflection->class->name) === self::REFLECTION_PROPERTY) {
            $nameVar = $reflection->getProperty(self::PROP_DECLARING_CLASS_NAME)->resolveIndirect();
            if (Variable::TYPE_STRING !== $nameVar->type) {
                throw new \LogicException('ReflectionProperty missing declaring class name');
            }

            return $nameVar->toString();
        }
        $propName = self::isReflectionMethodObject($reflection)
            ? self::PROP_REFLECTION_METHOD_CLASS
            : self::PROP_CLASS_NAME;
        $nameVar = $reflection->getProperty($propName)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionClass missing target class name');
        }

        return $nameVar->toString();
    }

    /** ReflectionClass::getShortName() — unqualified class name (ext/reflection/php_reflection.c). */
    public static function shortClassNameFromReflection(ObjectEntry $reflection): string
    {
        $name = self::classNameFromReflection($reflection);
        $pos = strrpos($name, '\\');
        if (false === $pos) {
            return $name;
        }

        return substr($name, $pos + 1);
    }

    /** ReflectionClass::getNamespaceName() — php-src prefix before last backslash (#22087). */
    public static function classNamespaceNameFromReflection(ObjectEntry $reflection): string
    {
        return self::globalConstantNamespaceName(self::classNameFromReflection($reflection));
    }

    /** ReflectionClass::inNamespace() — php-src (#22087). */
    public static function classInNamespaceFromReflection(ObjectEntry $reflection): bool
    {
        return '' !== self::classNamespaceNameFromReflection($reflection);
    }

    /**
     * ReflectionClass::{isSubclassOf,implementsInterface} — string|ReflectionClass operand (#6302).
     */
    public static function classNameFromReflectionClassArg(
        Variable $arg,
        string $method,
        string $param = 'class'
    ): string {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_STRING === $arg->type) {
            return $arg->toString();
        }
        if (Variable::TYPE_OBJECT === $arg->type) {
            $obj = $arg->toObject();
            if (!self::isReflectionClassObject($obj)) {
                throw new \TypeError(
                    'ReflectionClass::'.$method.'(): Argument #1 ($'.$param.') must be of type string|ReflectionClass, '
                    .$obj->class->name.' given'
                );
            }

            return self::classNameFromReflection($obj);
        }

        throw new \TypeError(
            'ReflectionClass::'.$method.'(): Argument #1 ($'.$param.') must be of type string|ReflectionClass, '
            .self::valueTypeLabel($arg).' given'
        );
    }

    /**
     * @return array{0: ObjectEntry, 1: ClassEntry, 2: \PHPCompiler\VM\Context}
     */
    public static function requireReflectedClassEntry(Frame $frame, Variable $receiver): array
    {
        $obj = self::requireReflectionClass($frame, $receiver);
        $ctx = VmReflection::requireContext($frame);
        $className = self::classNameFromReflection($obj);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionClass refers to unknown class in this compiler build');
        }

        return [$obj, $entry, $ctx];
    }

    /** php-src zim_ReflectionClass_isFinal — ce->ce_flags & ZEND_ACC_FINAL (#18297, #26531 enums). */
    public static function reflectionClassIsFinal(ClassEntry $entry): bool
    {
        return $entry->isFinal || $entry->isEnum;
    }

    /** php-src zim_ReflectionClass_isInterface — ce->ce_flags & ZEND_ACC_INTERFACE (#18335). */
    public static function reflectionClassIsInterface(ClassEntry $entry): bool
    {
        return $entry->isInterface;
    }

    /** php-src zim_ReflectionClass_isTrait — ce->ce_flags & ZEND_ACC_TRAIT (#18335). */
    public static function reflectionClassIsTrait(ClassEntry $entry): bool
    {
        return $entry->isTrait;
    }

    /** php-src zim_ReflectionClass_isIterateable — concrete Traversable, not interfaces (#18297, #18324). */
    public static function reflectionClassIsIterateable(ClassEntry $entry, Context $ctx): bool
    {
        if ($entry->isInterface) {
            return false;
        }

        return InterfaceCheck::entryImplements($entry, 'traversable', $ctx);
    }

    /** php-src zim_ReflectionClass_isInstantiable — abstract/interface/trait/enum/static/private ctor (#6302). */
    public static function reflectionClassIsInstantiable(ClassEntry $entry, ?Context $ctx = null): bool
    {
        if ($entry->isInterface || $entry->isTrait || $entry->isEnum || $entry->isAbstract || $entry->isStatic) {
            return false;
        }
        if ([] !== $entry->abstractMethods) {
            return false;
        }
        $ctorLc = '__construct';
        // Walk parents: Dom\HTMLDocument inherits Dom\Node's private final __construct (#26059).
        if (null !== $ctx) {
            foreach (VmReflection::classHierarchyChain($entry, $ctx) as $class) {
                if (!isset($class->methods[$ctorLc])) {
                    continue;
                }
                $flags = $class->methodVisibility[$ctorLc] ?? 0;

                return 0 === ($flags & \PHPCfg\Func::FLAG_PRIVATE);
            }

            return true;
        }
        if (!isset($entry->methods[$ctorLc])) {
            return true;
        }
        $flags = $entry->methodVisibility[$ctorLc] ?? 0;

        return 0 === ($flags & \PHPCfg\Func::FLAG_PRIVATE);
    }

    /**
     * ReflectionClass::newInstance() / newInstanceArgs() — php-src zim_ReflectionClass_newInstanceArgs (#22086).
     *
     * @param list<Variable> $ctorArgs
     */
    public static function instantiateReflectedClass(
        VmEngine $vm,
        ClassEntry $entry,
        array $ctorArgs,
    ): ObjectEntry {
        if (!self::reflectionClassIsInstantiable($entry)) {
            self::throwReflectionException('Class '.$entry->name.' is not instantiable');
        }
        ReservedBuiltinClass::assertUserInstantiable($entry);
        $object = new ObjectEntry($entry);
        $vm->initInstancePropertyDefaults($object);
        $thisVar = new Variable(Variable::TYPE_OBJECT);
        $thisVar->object($object);
        if (null !== $object->constructor) {
            $ctx = $vm->context;
            self::invokeAttributeConstructor($vm, $ctx, $object->constructor, $thisVar, $ctorArgs);
            $object->constructed = true;
        } else {
            $object->constructed = true;
        }

        return $object;
    }

    /**
     * ReflectionClass lazy factory arg — ReflectionClass receiver (instance ABI) or class-name string.
     *
     * Used by JIT Call\ReflectionClassNewLazy* and legacy static factory helpers (#6399, #22527).
     * Zend newLazyGhost/newLazyProxy are instance methods; prefer requireReflectedClassEntry on VM.
     */
    public static function classNameFromLazyFactoryArg(Variable $arg, string $method = 'newLazyGhost'): string
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_STRING === $arg->type) {
            return $arg->toString();
        }
        if (Variable::TYPE_OBJECT === $arg->type) {
            $obj = $arg->toObject();
            if (strtolower($obj->class->name) !== self::REFLECTION_CLASS) {
                throw new \TypeError(
                    'ReflectionClass::'.$method.'(): Argument #1 ($class) must be of type string, '
                    .$obj->class->name.' given'
                );
            }

            return self::classNameFromReflection($obj);
        }

        throw new \TypeError(
            'ReflectionClass::'.$method.'(): Argument #1 ($class) must be of type string, '
            .self::valueTypeLabel($arg).' given'
        );
    }

    private static function valueTypeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'unknown',
        };
    }

    /**
     * Wire declared properties + sidecar fields on ReflectionEnum*Case wrappers (#10000, #16331).
     */
    public static function initReflectionEnumCaseMetadata(
        ObjectEntry $reflection,
        string $enumClassName,
        string $caseCanonicalName
    ): void {
        $reflection->reflectionEnumClassName = $enumClassName;
        $reflection->reflectionEnumCaseName = $caseCanonicalName;
        $reflection->getProperty(self::PROP_CLASS_NAME)->string($caseCanonicalName);
        $reflection->getProperty(self::PROP_ENUM_CLASS_NAME)->string($enumClassName);
    }

    public static function enumCaseNameFromReflection(ObjectEntry $reflection): string
    {
        if (null !== $reflection->reflectionEnumCaseName && '' !== $reflection->reflectionEnumCaseName) {
            return $reflection->reflectionEnumCaseName;
        }
        $nameVar = $reflection->getProperty(self::PROP_CLASS_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionEnumUnitCase missing case name');
        }

        return $nameVar->toString();
    }

    public static function enumClassNameFromReflection(ObjectEntry $reflection): string
    {
        if (null !== $reflection->reflectionEnumClassName && '' !== $reflection->reflectionEnumClassName) {
            return $reflection->reflectionEnumClassName;
        }
        $nameVar = $reflection->getProperty(self::PROP_ENUM_CLASS_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionEnumUnitCase missing enum class name');
        }

        return $nameVar->toString();
    }

    public static function methodNameFromReflection(ObjectEntry $reflection): string
    {
        // ReflectionParameter stores the method on `method`; ReflectionMethod on `name` (#18338).
        if ($reflection->hasProperty(self::PROP_METHOD_NAME)) {
            $methodNameVar = $reflection->getProperty(self::PROP_METHOD_NAME)->resolveIndirect();
            if (Variable::TYPE_STRING === $methodNameVar->type) {
                return $methodNameVar->toString();
            }
        }
        $nameVar = $reflection->getProperty(self::PROP_REFLECTION_METHOD_FUNC)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionMethod missing method name');
        }

        return $nameVar->toString();
    }

    public static function requireReflectionProperty(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionProperty method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_PROPERTY) {
            throw new \LogicException('Expected ReflectionProperty instance');
        }

        return $obj;
    }

    public static function requireReflectionFunction(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionFunction method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_FUNCTION) {
            throw new \LogicException('Expected ReflectionFunction instance');
        }

        return $obj;
    }

    public static function requireReflectionConstant(Frame $frame, Variable $receiver): ObjectEntry
    {
        return self::requireReflectionClassConstant($frame, $receiver);
    }

    public static function requireReflectionClassConstant(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionClassConstant method called without object');
        }
        $obj = $receiver->toObject();
        $classLc = strtolower($obj->class->name);
        // php-src: ReflectionEnumUnitCase / ReflectionEnumBackedCase extend ReflectionClassConstant (#19785).
        if (self::REFLECTION_CLASS_CONSTANT !== $classLc
            && self::REFLECTION_CONSTANT !== $classLc
            && !self::isReflectionEnumCaseObject($obj)
        ) {
            throw new \LogicException('Expected ReflectionClassConstant instance');
        }

        return $obj;
    }

    public static function propertyNameFromReflection(ObjectEntry $reflection): string
    {
        $nameVar = $reflection->getProperty(self::PROP_PROPERTY_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionProperty missing property name');
        }

        return $nameVar->toString();
    }

    public static function isDynamicReflectionProperty(ObjectEntry $reflection): bool
    {
        $flag = $reflection->getProperty(self::PROP_IS_DYNAMIC)->resolveIndirect();
        if (Variable::TYPE_BOOLEAN !== $flag->type) {
            return false;
        }

        return $flag->toBool();
    }

    /**
     * Declaring class for a ReflectionProperty — stored at construction or resolved from metadata (#9878).
     */
    public static function declaringClassNameFromReflectionProperty(ObjectEntry $reflection, Context $ctx): string
    {
        $declVar = $reflection->getProperty(self::PROP_DECLARING_CLASS_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING === $declVar->type && '' !== $declVar->toString()) {
            return $declVar->toString();
        }
        $className = self::classNameFromReflection($reflection);
        $property = self::propertyNameFromReflection($reflection);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionProperty refers to unknown class in this compiler build');
        }

        return VmReflection::declaringClassNameForPropertyLookup($entry, $property, $ctx);
    }

    /**
     * php-src ext/reflection/php_reflection.c — declaring class for a method on $entry (#15658, #22582).
     *
     * Zend ReflectionMethod::$class / getDeclaringClass() use the method's scope ce:
     * inherited methods → parent declarer; trait imports → composing class (not the trait),
     * including when reflected via a subclass that inherited the imported method.
     */
    public static function declaringClassNameForMethod(Context $ctx, ClassEntry $entry, string $methodName): string
    {
        $methodLc = strtolower($methodName);
        // Walk parents: subclass may inherit a trait method without copying traitMethodSources.
        foreach (VmReflection::classHierarchyChain($entry, $ctx) as $class) {
            if (isset($class->traitMethodSources[$methodLc])) {
                return $class->name;
            }
        }
        $declLc = $entry->methodDeclaringClassLc[$methodLc] ?? null;
        if (null !== $declLc && isset($ctx->classes[$declLc])) {
            $decl = $ctx->classes[$declLc];
            // methodDeclaringClassLc may name the trait; Zend scope is never the trait (#15658).
            if (!$decl->isTrait) {
                return $decl->name;
            }
        }
        $chain = $entry->isInterface
            ? VmReflection::interfaceDeclarationChain($entry, $ctx)
            : VmReflection::classHierarchyChain($entry, $ctx);
        foreach ($chain as $class) {
            if ($class->isTrait) {
                continue;
            }
            if (!isset($class->methods[$methodLc]) && !isset($class->abstractMethods[$methodLc])) {
                continue;
            }
            $vis = $class->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
            // Parent-private hidden on child (#7191), except ctor/dtor Reflection lookup (#26059).
            if (($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0 && $class !== $entry
                && '__construct' !== $methodLc && '__destruct' !== $methodLc) {
                continue;
            }

            return $class->name;
        }

        return $entry->name;
    }

    /** php-src ext/reflection/php_reflection.c — ReflectionMethod::getDeclaringClass() (#15658). */
    public static function declaringClassNameFromReflectionMethod(ObjectEntry $reflection, Context $ctx): string
    {
        $className = self::classNameFromReflection($reflection);
        $methodName = self::methodNameFromReflection($reflection);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionMethod refers to unknown class in this compiler build');
        }

        return self::declaringClassNameForMethod($ctx, $entry, $methodName);
    }

    public static function functionNameFromReflection(ObjectEntry $reflection): string
    {
        // ReflectionFunction public surface is `$name` (#22488); Parameter keeps internal `funcName`.
        if (strtolower($reflection->class->name) === self::REFLECTION_FUNCTION) {
            $nameVar = $reflection->getProperty(self::PROP_REFLECTION_FUNCTION_NAME)->resolveIndirect();
            if (Variable::TYPE_STRING !== $nameVar->type) {
                throw new \LogicException('ReflectionFunction missing function name');
            }

            return $nameVar->toString();
        }
        $nameVar = $reflection->getProperty(self::PROP_FUNC_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            $nameVar = $reflection->getProperty(self::PROP_FUNCTION_NAME)->resolveIndirect();
        }
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionFunction missing function name');
        }

        return $nameVar->toString();
    }

    public static function isReflectionFunctionAnonymous(ObjectEntry $reflection): bool
    {
        $state = $reflection->reflectionClosureState;

        return null !== $state && $state->isUserClosure();
    }

    /**
     * php-src: zim_ReflectionClass_isAnonymous() — ce->ce_flags & ZEND_ACC_ANON_CLASS;
     * anonymous compile names contain @anonymous (MagicStringResolver / zend_compile.c).
     */
    public static function isReflectionClassAnonymous(ObjectEntry $reflection): bool
    {
        return str_contains(self::classNameFromReflection($reflection), '@anonymous');
    }

    /**
     * isAnonymousClass() — php-src zif_is_anonymous_class (ext/reflection/php_reflection.c, #19969).
     */
    public static function isAnonymousClassObject(Variable $var, string $function = 'isAnonymousClass'): bool
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            return false;
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($object) must be of type object, %s given',
                $function,
                ObjectHandleSupport::vmTypeName($var->type)
            ));
        }

        return str_contains($var->toObject()->class->name, '@anonymous');
    }

    public static function isReflectionInternalFunction(ObjectEntry $reflection): bool
    {
        return $reflection->reflectionIsInternalFunction;
    }

    /**
     * Resolve a named function for ReflectionFunction::__construct (user or internal).
     *
     * php-src: ext/reflection/php_reflection.c — zend_lookup_internal_function()
     *
     * Symbols kept in the VM table for paren-call lowering but hidden from
     * function_exists() (exit/die on the Zend 8.2 reference profile) must also
     * fail Reflection — same as Zend (#23687, re-#14738 / #20575).
     */
    public static function resolveFunctionForReflection(Context $ctx, string $functionName): Func
    {
        $functionName = VmReflection::normalizeGlobalIntrospectionName($functionName);
        $lc = strtolower($functionName);
        $func = $ctx->functions[$lc] ?? null;
        if (null === $func || !VmReflection::isVisibleToFunctionExists($functionName)) {
            self::throwReflectionException(self::functionNotFoundMessage($functionName));
        }

        return $func;
    }

    /**
     * @return \PHPCompiler\Func\PHP
     */
    public static function resolveFunctionFromReflection(Context $ctx, ObjectEntry $reflection): \PHPCompiler\Func\PHP
    {
        if ($reflection->reflectionIsInternalFunction) {
            self::throwReflectionException(
                self::functionNotFoundMessage(self::functionNameFromReflection($reflection))
            );
        }
        $closure = $reflection->reflectionClosureState;
        if (null !== $closure) {
            // fromCallable / FCC / getClosure: walk the wrapped target, not the empty stub (#25559).
            $underlying = $closure->wrappedFunc ?? $closure->func;
            if ($underlying instanceof PhpFunc) {
                return $underlying;
            }

            return $closure->func;
        }

        return self::resolveUserFunction($ctx, self::functionNameFromReflection($reflection));
    }

    /**
     * php-src: ext/reflection/php_reflection.c — reflection_function_is_generator().
     */
    public static function isReflectionFunctionGenerator(Context $ctx, ObjectEntry $reflection): bool
    {
        if ($reflection->reflectionIsInternalFunction ?? false) {
            return false;
        }
        $func = self::resolveFunctionFromReflection($ctx, $reflection);

        return $func->block->isGenerator;
    }

    /**
     * php-src: zim_reflection_function_abstract_isVariadic (#22045).
     *
     * Shared by ReflectionFunction / ReflectionMethod in php-src; method path is
     * {@see self::isReflectionMethodVariadic()}.
     */
    public static function isReflectionFunctionVariadic(Context $ctx, ObjectEntry $reflection): bool
    {
        if ($reflection->reflectionIsInternalFunction ?? false) {
            $name = self::functionNameFromReflection($reflection);

            return null !== BuiltinParamNames::variadicParamIndexForFunction($name);
        }
        $func = self::resolveFunctionFromReflection($ctx, $reflection);

        return null !== $func->block->variadicParamIndex;
    }

    /**
     * php-src: ext/reflection/php_reflection.c — reflection_method_is_generator().
     */
    public static function isReflectionMethodGenerator(Context $ctx, ObjectEntry $reflection): bool
    {
        $className = self::classNameFromReflection($reflection);
        $methodName = self::methodNameFromReflection($reflection);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            return false;
        }
        $func = self::resolveDeclaredMethodFunc($ctx, $entry, strtolower($methodName));
        if (!($func instanceof PhpFunc)) {
            return false;
        }

        return $func->block->isGenerator;
    }

    private static function resolveDeclaredMethodFunc(Context $ctx, ClassEntry $entry, string $methodLc): ?Func
    {
        $current = $entry;
        while (null !== $current) {
            if (isset($current->methods[$methodLc])) {
                $method = $current->methods[$methodLc];
                if ($method instanceof Func) {
                    return $method;
                }
            }
            $parentLc = $current->parentLc ?? '';
            if ('' === $parentLc) {
                break;
            }
            $current = $ctx->classes[$parentLc] ?? null;
        }

        return null;
    }

    public static function constantNameFromReflection(ObjectEntry $reflection): string
    {
        if (self::isReflectionEnumCaseObject($reflection)) {
            return self::enumCaseNameFromReflection($reflection);
        }
        // ReflectionClassConstant public `$name` is the constant name (#22503).
        $propName = strtolower($reflection->class->name) === self::REFLECTION_CLASS_CONSTANT
            ? self::PROP_REFLECTION_CLASS_CONSTANT_NAME
            : self::PROP_CONSTANT_NAME;
        $nameVar = $reflection->getProperty($propName)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionConstant missing constant name');
        }

        return $nameVar->toString();
    }

    public static function paramPositionFromReflection(ObjectEntry $reflection): int
    {
        $posVar = $reflection->getProperty(self::PROP_PARAM_POSITION)->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $posVar->type) {
            throw new \LogicException('ReflectionParameter missing position');
        }

        return $posVar->toInt();
    }

    /**
     * Parse Reflection*::getAttributes(?string $name, int $flags) optional args (#11471).
     *
     * @return array{0: ?string, 1: int}
     */
    public static function getAttributesFilterArgs(Frame $frame, string $methodLabel): array
    {
        $filter = null;
        $flags = 0;
        if (isset($frame->calledArgs[1])) {
            $arg1 = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg1->type) {
                $filter = VmReflection::stringArg($arg1, $methodLabel.' name', 1);
            }
        }
        if (isset($frame->calledArgs[2])) {
            $arg2 = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $arg2->type) {
                throw new \TypeError(
                    $methodLabel.': Argument #2 ($flags) must be of type int, '
                    .EnumCaseSupport::typeNameForVariable($arg2).' given'
                );
            }
            $flags = $arg2->toInt();
        }

        return [$filter, $flags];
    }

    /**
     * @param list<AttributeEntry> $all
     *
     * @return list<AttributeEntry>
     */
    public static function filterEntriesByName(Context $ctx, array $all, ?string $filter, int $flags = 0): array
    {
        if (null === $filter || '' === $filter) {
            return $all;
        }
        $out = [];
        foreach ($all as $entry) {
            if (!$entry instanceof AttributeEntry) {
                continue;
            }
            if (self::attributeClassMatchesFilter($ctx, $entry->name, $filter, $flags)) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $all
     *
     * @return list<string>
     */
    public static function filterByName(Context $ctx, array $all, ?string $filter, int $flags = 0): array
    {
        if (null === $filter || '' === $filter) {
            return $all;
        }
        $out = [];
        foreach ($all as $name) {
            if (self::attributeClassMatchesFilter($ctx, $name, $filter, $flags)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /** php-src reflection_get_attributes_impl name / IS_INSTANCEOF filter (#11471). */
    public static function attributeClassMatchesFilter(
        Context $ctx,
        string $attributeClass,
        string $filter,
        int $flags
    ): bool {
        if (($flags & self::REFLECTION_ATTRIBUTE_IS_INSTANCEOF) !== 0) {
            $entry = VmReflection::resolveClassEntry($ctx, $attributeClass);

            return null !== $entry && VmReflection::isInstanceOf($ctx, $entry, $filter);
        }
        $want = strtolower(ltrim($filter, '\\'));
        $cand = strtolower(ltrim($attributeClass, '\\'));

        return $cand === $want || str_ends_with($cand, '\\'.$want);
    }

    public static function requireReflectionType(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionType method called without object');
        }
        $obj = $receiver->toObject();
        if (!ReflectionTypeSupport::isReflectionTypeClass(strtolower($obj->class->name))) {
            throw new \LogicException('Expected ReflectionType instance');
        }

        return $obj;
    }

    public static function paramIndexFromReflection(ObjectEntry $reflection): int
    {
        $idxVar = $reflection->getProperty(self::PROP_PARAM_INDEX)->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $idxVar->type) {
            throw new \LogicException('ReflectionParameter missing parameter index');
        }

        return $idxVar->toInt();
    }

    public static function paramNameFromReflection(ObjectEntry $reflection): string
    {
        $nameVar = $reflection->getProperty(self::PROP_PARAM_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionParameter missing parameter name');
        }

        return $nameVar->toString();
    }

    public static function valueTypeLabelPublic(Variable $var): string
    {
        return self::valueTypeLabel($var);
    }

    /**
     * @return list<AttributeEntry>
     */
    public static function parameterAttributeEntries(Context $ctx, ObjectEntry $reflection): array
    {
        $classNameVar = $reflection->getProperty(self::PROP_PARAM_CLASS)->resolveIndirect();
        if (Variable::TYPE_STRING === $classNameVar->type) {
            $className = $classNameVar->toString();
            $method = self::methodNameFromReflection($reflection);
            $entry = VmReflection::resolveClassEntry($ctx, $className);
            if (null === $entry) {
                return [];
            }
            $methodLc = strtolower($method);
            $position = self::paramPositionFromReflection($reflection);
            $params = $entry->methodParameterMetadata[$methodLc] ?? [];
            $paramMeta = $params[$position] ?? null;

            return null !== $paramMeta ? $paramMeta->attributes : [];
        }

        $funcNameVar = $reflection->getProperty(self::PROP_FUNC_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $funcNameVar->type) {
            return [];
        }
        $func = self::resolveFunctionForReflectionParameter($ctx, $reflection);
        $index = self::paramIndexFromReflection($reflection);
        if (isset($func->parameterMetadata[$index])) {
            return $func->parameterMetadata[$index]->attributes;
        }
        if (!isset($func->block->paramSensitive[$index])) {
            return [];
        }

        return [new AttributeEntry('SensitiveParameter')];
    }

    public static function parameterIsSensitive(Context $ctx, ObjectEntry $reflection): bool
    {
        foreach (self::parameterAttributeEntries($ctx, $reflection) as $entry) {
            if (AttributeNames::isSensitiveParameter([$entry->name])) {
                return true;
            }
        }

        return false;
    }

    public static function parameterIsDeprecated(Context $ctx, ObjectEntry $reflection): bool
    {
        foreach (self::parameterAttributeEntries($ctx, $reflection) as $entry) {
            $meta = DeprecatedMetadata::fromAttributeEntry($entry);
            if (null !== $meta && $meta->isDeprecatedForReflection()) {
                return true;
            }
        }

        return false;
    }

    public static function parameterIsPromoted(Context $ctx, ObjectEntry $reflection): bool
    {
        $classNameVar = $reflection->getProperty(self::PROP_PARAM_CLASS)->resolveIndirect();
        if (Variable::TYPE_STRING !== $classNameVar->type) {
            return false;
        }
        $className = $classNameVar->toString();
        $method = self::methodNameFromReflection($reflection);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            return false;
        }
        $methodLc = strtolower($method);
        $position = self::paramPositionFromReflection($reflection);
        $params = $entry->methodParameterMetadata[$methodLc] ?? [];
        $paramMeta = $params[$position] ?? null;

        return null !== $paramMeta && $paramMeta->isPromoted;
    }

    public static function resolveParameterBlock(Context $ctx, ObjectEntry $reflection): \PHPCompiler\Block
    {
        $classNameVar = $reflection->getProperty(self::PROP_PARAM_CLASS)->resolveIndirect();
        if (Variable::TYPE_STRING === $classNameVar->type) {
            $className = $classNameVar->toString();
            $method = self::methodNameFromReflection($reflection);
            $entry = VmReflection::resolveClassEntry($ctx, $className);
            if (null === $entry) {
                throw new \LogicException('ReflectionParameter refers to unknown class in this compiler build');
            }
            $methodLc = strtolower($method);
            $func = $entry->methods[$methodLc] ?? null;
            if (!$func instanceof \PHPCompiler\Func\PHP) {
                throw new \LogicException('ReflectionParameter refers to unknown method in this compiler build');
            }

            return $func->block;
        }

        return self::resolveFunctionForReflectionParameter($ctx, $reflection)->block;
    }

    public static function parameterIndexForReflection(ObjectEntry $reflection): int
    {
        $classNameVar = $reflection->getProperty(self::PROP_PARAM_CLASS)->resolveIndirect();
        if (Variable::TYPE_STRING === $classNameVar->type) {
            return self::paramPositionFromReflection($reflection);
        }

        return self::paramIndexFromReflection($reflection);
    }

    public static function parameterIsVariadic(\PHPCompiler\Block $block, int $paramIndex): bool
    {
        return null !== $block->variadicParamIndex && $block->variadicParamIndex === $paramIndex;
    }

    /**
     * php-src zim_reflection_parameter_isVariadic — user Func\PHP blocks + internals (#24461).
     *
     * Internals have no user block; re-resolving via {@see resolveParameterBlock()} throws
     * "Function …() does not exist". Use BuiltinParamNames / BuiltinInternalArgInfo instead
     * (same path as {@see parameterIsOptional()} / {@see isReflectionFunctionVariadic()}).
     */
    public static function parameterIsVariadicForReflection(Context $ctx, ObjectEntry $reflection): bool
    {
        if (self::parameterIsInternal($ctx, $reflection)) {
            $index = self::parameterIndexForReflection($reflection);
            $callableLc = strtolower(self::internalCallableName($ctx, $reflection));

            return self::internalParameterIsVariadic($ctx, $reflection, $callableLc, $index);
        }
        $block = self::resolveParameterBlock($ctx, $reflection);
        $index = self::parameterIndexForReflection($reflection);

        return self::parameterIsVariadic($block, $index);
    }

    public static function parameterIsOptional(Context $ctx, ObjectEntry $reflection): bool
    {
        if (self::parameterIsInternal($ctx, $reflection)) {
            return self::internalParameterIsOptional($ctx, $reflection);
        }
        $block = self::resolveParameterBlock($ctx, $reflection);
        $index = self::parameterIndexForReflection($reflection);

        return self::parameterIsVariadic($block, $index)
            || ParamArgumentCountError::parameterHasDefault($block, $index);
    }

    public static function parameterDefaultValueIsAvailableForReflection(
        Context $ctx,
        ObjectEntry $reflection,
    ): bool {
        if (self::parameterIsInternal($ctx, $reflection)) {
            return self::internalParameterDefaultValueIsAvailable($ctx, $reflection);
        }
        $block = self::resolveParameterBlock($ctx, $reflection);
        $index = self::parameterIndexForReflection($reflection);

        return self::parameterDefaultValueIsAvailable($block, $index);
    }

    public static function copyParameterDefaultValueForReflection(
        Variable $dest,
        Context $ctx,
        ObjectEntry $reflection,
    ): bool {
        if (self::parameterIsInternal($ctx, $reflection)) {
            return self::copyInternalParameterDefaultValue($dest, $ctx, $reflection);
        }
        $block = self::resolveParameterBlock($ctx, $reflection);
        $index = self::parameterIndexForReflection($reflection);
        $value = $ctx->runtime->vm()->evaluateParameterDefaultForReflection($block, $index);
        if (null === $value) {
            return false;
        }
        $dest->copyFrom($value);

        return true;
    }

    /**
     * ReflectionParameter::isDefaultValueConstant() (#22026, zim_reflection_parameter_is_default_value_constant).
     * Throws when no default is available (php-src "Internal error: Failed to retrieve the default value").
     */
    public static function parameterDefaultValueIsConstantForReflection(
        Context $ctx,
        ObjectEntry $reflection,
    ): bool {
        if (!self::parameterDefaultValueIsAvailableForReflection($ctx, $reflection)) {
            self::throwReflectionException('Internal error: Failed to retrieve the default value');
        }
        if (self::parameterIsInternal($ctx, $reflection)) {
            return null !== self::internalParameterDefaultConstantName($ctx, $reflection);
        }
        $block = self::resolveParameterBlock($ctx, $reflection);
        $index = self::parameterIndexForReflection($reflection);

        return isset($block->paramDefaultConstantNames[$index]);
    }

    /**
     * ReflectionParameter::getDefaultValueConstantName() (#22026).
     * Returns null when the default exists but is not a constant fetch.
     */
    public static function parameterDefaultValueConstantNameForReflection(
        Context $ctx,
        ObjectEntry $reflection,
    ): ?string {
        if (!self::parameterDefaultValueIsAvailableForReflection($ctx, $reflection)) {
            self::throwReflectionException('Internal error: Failed to retrieve the default value');
        }
        if (self::parameterIsInternal($ctx, $reflection)) {
            return self::internalParameterDefaultConstantName($ctx, $reflection);
        }
        $block = self::resolveParameterBlock($ctx, $reflection);
        $index = self::parameterIndexForReflection($reflection);

        return $block->paramDefaultConstantNames[$index] ?? null;
    }

    /** php-src round() $mode = RoundingMode::HalfAwayFromZero (#28535). */
    private static function internalParameterDefaultConstantName(
        Context $ctx,
        ObjectEntry $reflection,
    ): ?string {
        if (!CompilerVersion::supportsRoundingModeEnum()) {
            return null;
        }
        $callableLc = strtolower(self::internalCallableName($ctx, $reflection));
        $index = self::parameterIndexForReflection($reflection);
        if ('round' === $callableLc && 2 === $index) {
            return 'RoundingMode::HalfAwayFromZero';
        }

        return null;
    }

    /**
     * php-src zim_reflection_parameter_allowsNull — no declared type (incl. untyped
     * variadic) ⇒ true; otherwise follow the ReflectionType nullability rules (#22524).
     * php-cfg stores absent types as {@see CfgType\Mixed_}; use {@see declaredParamTypeForReflection}.
     */
    public static function parameterAllowsNull(Context $ctx, ObjectEntry $reflection): bool
    {
        if (self::parameterIsInternal($ctx, $reflection)) {
            return self::internalParameterAllowsNull($ctx, $reflection);
        }
        $type = self::declaredParamTypeForReflection($ctx, $reflection);
        if (null === $type) {
            return true;
        }

        return ReflectionTypeSupport::allowsNullFromCfg($type);
    }

    public static function parameterIsPassedByReference(Context $ctx, ObjectEntry $reflection): bool
    {
        if (self::parameterIsInternal($ctx, $reflection)) {
            return self::internalParameterIsPassedByReference($ctx, $reflection);
        }
        $block = self::resolveParameterBlock($ctx, $reflection);
        $index = self::parameterIndexForReflection($reflection);

        return isset($block->paramByRef[$index]);
    }

    public static function parameterCanBePassedByValue(Context $ctx, ObjectEntry $reflection): bool
    {
        // php-src zim_ReflectionParameter_canBePassedByValue:
        // RETVAL_BOOL(ZEND_ARG_SEND_MODE(param->arg_info) != ZEND_SEND_BY_REF)
        // User params are never ZEND_SEND_PREFER_REF; forced by-ref ⇒ false (#22145).
        return !self::parameterIsPassedByReference($ctx, $reflection);
    }

    /**
     * E_DEPRECATED for legacy ReflectionParameter type probes (php-src ZEND_ACC_DEPRECATED; #22408).
     */
    public static function emitLegacyParameterTypeApiDeprecation(Frame $frame, string $method): void
    {
        $vm = VmEngine::running();
        if (null === $vm) {
            return;
        }
        $vm->context->errors->internalDeprecated(
            'Method ReflectionParameter::'.$method.'() is deprecated',
            $vm->context,
            $frame
        );
    }

    /**
     * ReflectionParameter::isArray() — pure array/?array only (php-src zim_ReflectionParameter_isArray; #22408).
     */
    public static function parameterIsArray(Context $ctx, ObjectEntry $reflection): bool
    {
        return 'array' === self::pureBuiltinTypeNameWithoutNull(
            self::declaredParamTypeForReflection($ctx, $reflection)
        );
    }

    /**
     * ReflectionParameter::isCallable() — pure callable/?callable only (#22408).
     */
    public static function parameterIsCallable(Context $ctx, ObjectEntry $reflection): bool
    {
        return 'callable' === self::pureBuiltinTypeNameWithoutNull(
            self::declaredParamTypeForReflection($ctx, $reflection)
        );
    }

    /**
     * ReflectionParameter::getClass() — single class-name type → ReflectionClass or null (#22408).
     */
    public static function parameterGetClass(Context $ctx, ObjectEntry $reflection): ?ObjectEntry
    {
        $className = self::singleClassNameFromParamType(
            $ctx,
            $reflection,
            self::declaredParamTypeForReflection($ctx, $reflection)
        );
        if (null === $className) {
            return null;
        }

        return self::newReflectionClassObjectForName($ctx, $className);
    }

    /**
     * ReflectionParameter::getDeclaringFunction() — ReflectionMethod or ReflectionFunction (#22408).
     */
    public static function parameterDeclaringFunction(Context $ctx, ObjectEntry $reflection): ObjectEntry
    {
        $className = self::parameterDeclaringClassNameOrNull($reflection);
        if (null !== $className) {
            $entry = VmReflection::resolveClassEntry($ctx, $className);
            if (null === $entry) {
                self::throwReflectionException(self::classNotFoundMessage($className));
            }
            $methodName = self::methodNameFromReflection($reflection);

            return self::newReflectionMethodObject($ctx, $entry, $methodName);
        }
        $closure = $reflection->reflectionClosureState;
        if (null !== $closure) {
            return self::reflectionFunctionFromClosureState($ctx, $closure);
        }

        return self::reflectionFunctionFromFunctionName(
            $ctx,
            self::functionNameFromReflection($reflection)
        );
    }

    /**
     * ReflectionParameter::getDeclaringClass() — declaring class or null for free functions (#22408).
     */
    public static function parameterDeclaringClass(Context $ctx, ObjectEntry $reflection): ?ObjectEntry
    {
        $className = self::parameterDeclaringClassNameOrNull($reflection);
        if (null === $className) {
            return null;
        }

        return self::newReflectionClassObjectForName($ctx, $className);
    }

    /**
     * ReflectionParameter::__toString() — php-src _parameter_string with empty indent (#22408).
     */
    public static function parameterReflectionToString(Context $ctx, ObjectEntry $reflection): string
    {
        $meta = self::parameterMetadataForReflection($ctx, $reflection);
        $index = self::parameterIndexForReflection($reflection);
        $line = self::formatParameterDumpLine($index, $meta);

        return trim($line);
    }

    /** @return ?string Declaring class name when this parameter belongs to a method. */
    private static function parameterDeclaringClassNameOrNull(ObjectEntry $reflection): ?string
    {
        $classNameVar = $reflection->getProperty(self::PROP_PARAM_CLASS)->resolveIndirect();
        if (Variable::TYPE_STRING !== $classNameVar->type) {
            return null;
        }
        $name = $classNameVar->toString();

        return '' !== $name ? $name : null;
    }

    /**
     * Pure builtin type name after stripping null (MAY_BE_* without null); null if not a single builtin.
     */
    private static function pureBuiltinTypeNameWithoutNull(?CfgType $type): ?string
    {
        if (null === $type) {
            return null;
        }
        if ($type instanceof CfgType\Nullable) {
            return self::pureBuiltinTypeNameWithoutNull($type->subtype);
        }
        if ($type instanceof CfgType\Union_) {
            $nonNull = [];
            foreach ($type->types as $member) {
                if ($member instanceof CfgType\Literal && 'null' === strtolower($member->name)) {
                    continue;
                }
                if ($member instanceof CfgType\Nullable) {
                    $inner = self::pureBuiltinTypeNameWithoutNull($member->subtype);
                    if (null === $inner) {
                        return null;
                    }
                    $nonNull[] = $inner;
                    continue;
                }
                if (!($member instanceof CfgType\Literal)) {
                    return null;
                }
                $nonNull[] = strtolower($member->name);
            }
            if (1 !== \count($nonNull)) {
                return null;
            }

            return $nonNull[0];
        }
        if ($type instanceof CfgType\Literal) {
            $name = strtolower($type->name);
            if ('null' === $name) {
                return null;
            }

            return $name;
        }

        return null;
    }

    /**
     * Single class-name hint for getClass() (ZEND_TYPE_HAS_NAME semantics; #22408).
     */
    private static function singleClassNameFromParamType(
        Context $ctx,
        ObjectEntry $reflection,
        ?CfgType $type
    ): ?string {
        if (null === $type) {
            return null;
        }
        if ($type instanceof CfgType\Nullable) {
            return self::singleClassNameFromParamType($ctx, $reflection, $type->subtype);
        }
        if ($type instanceof CfgType\Union_) {
            $classNames = [];
            foreach ($type->types as $member) {
                if ($member instanceof CfgType\Literal && 'null' === strtolower($member->name)) {
                    continue;
                }
                if ($member instanceof CfgType\Nullable) {
                    $inner = self::singleClassNameFromParamType($ctx, $reflection, $member->subtype);
                    if (null === $inner) {
                        return null;
                    }
                    $classNames[] = $inner;
                    continue;
                }
                if ($member instanceof CfgType\Reference) {
                    $classNames[] = self::resolveParamClassTypeName(
                        $ctx,
                        $reflection,
                        ReflectionTypeSupport::cfgTypeString($member)
                    );
                    continue;
                }
                if ($member instanceof CfgType\Literal) {
                    $lit = strtolower($member->name);
                    if ('iterable' === $lit) {
                        $classNames[] = 'Traversable';
                        continue;
                    }
                    // Builtin masks (int, string, …) are ignored for ZEND_TYPE_HAS_NAME.
                    if (ReflectionTypeSupport::isBuiltinTypeNamePublic($lit)) {
                        continue;
                    }
                    $classNames[] = self::resolveParamClassTypeName($ctx, $reflection, $member->name);
                    continue;
                }

                return null;
            }
            $unique = array_values(array_unique($classNames));
            if (1 !== \count($unique)) {
                return null;
            }

            return $unique[0];
        }
        if ($type instanceof CfgType\Reference) {
            return self::resolveParamClassTypeName(
                $ctx,
                $reflection,
                ReflectionTypeSupport::cfgTypeString($type)
            );
        }
        if ($type instanceof CfgType\Literal) {
            $lit = strtolower($type->name);
            if ('iterable' === $lit) {
                return 'Traversable';
            }
            if (ReflectionTypeSupport::isBuiltinTypeNamePublic($lit)) {
                return null;
            }

            return self::resolveParamClassTypeName($ctx, $reflection, $type->name);
        }

        return null;
    }

    /** Resolve self/parent relative to the parameter's declaring method scope. */
    private static function resolveParamClassTypeName(
        Context $ctx,
        ObjectEntry $reflection,
        string $className
    ): string {
        $lc = strtolower(ltrim($className, '\\'));
        if ('self' === $lc || 'parent' === $lc) {
            $scopeName = self::parameterDeclaringClassNameOrNull($reflection);
            if (null === $scopeName) {
                self::throwReflectionException(
                    'self' === $lc
                        ? 'Parameter uses "self" as type but function is not a class member'
                        : 'Parameter uses "parent" as type but function is not a class member'
                );
            }
            $entry = VmReflection::resolveClassEntry($ctx, $scopeName);
            if (null === $entry) {
                self::throwReflectionException(self::classNotFoundMessage($scopeName));
            }
            if ('self' === $lc) {
                return $entry->name;
            }
            if (null === $entry->parentLc) {
                self::throwReflectionException(
                    'Parameter uses "parent" as type although class does not have a parent'
                );
            }
            $parent = $ctx->classes[$entry->parentLc] ?? null;
            if (null === $parent) {
                self::throwReflectionException(self::classNotFoundMessage($entry->parentLc));
            }

            return $parent->name;
        }

        return ltrim($className, '\\');
    }

    private static function parameterMetadataForReflection(
        Context $ctx,
        ObjectEntry $reflection
    ): ParameterMetadata {
        $index = self::parameterIndexForReflection($reflection);
        $className = self::parameterDeclaringClassNameOrNull($reflection);
        if (null !== $className) {
            $entry = VmReflection::resolveClassEntry($ctx, $className);
            if (null !== $entry) {
                $methodLc = strtolower(self::methodNameFromReflection($reflection));
                $params = $entry->methodParameterMetadata[$methodLc] ?? [];
                if (isset($params[$index])) {
                    return $params[$index];
                }
            }
        } elseif (!self::parameterIsInternal($ctx, $reflection)) {
            $func = self::resolveFunctionForReflectionParameter($ctx, $reflection);
            if (isset($func->parameterMetadata[$index])) {
                return $func->parameterMetadata[$index];
            }
        }

        $type = self::declaredParamTypeForReflection($ctx, $reflection);
        $typeString = null !== $type ? ReflectionTypeSupport::cfgTypeStringForDump($type) : null;
        $isVariadic = false;
        $byRef = self::parameterIsPassedByReference($ctx, $reflection);
        $isOptional = self::parameterIsOptional($ctx, $reflection);
        if (!self::parameterIsInternal($ctx, $reflection)) {
            $block = self::resolveParameterBlock($ctx, $reflection);
            $isVariadic = self::parameterIsVariadic($block, $index);
        }
        $defaultExport = null;
        if ($isOptional && !$isVariadic && self::parameterDefaultValueIsAvailableForReflection($ctx, $reflection)) {
            $tmp = new Variable();
            if (self::copyParameterDefaultValueForReflection($tmp, $ctx, $reflection)) {
                $defaultExport = self::formatReflectionScalar($tmp->resolveIndirect());
            }
        }

        return new ParameterMetadata(
            self::paramNameFromReflection($reflection),
            [],
            false,
            $isOptional,
            $isVariadic,
            $byRef,
            $typeString,
            $defaultExport
        );
    }

    /**
     * Declared parameter type for ReflectionParameter::getType()/hasType() (#18337, #22064, #25406).
     *
     * Implicit nullable (`string $s = null`) is reflected as {@see CfgType\Nullable} so
     * getType()/allowsNull() match php-src (#26469) even when CFG stored a bare type.
     */
    public static function declaredParamTypeForReflection(Context $ctx, ObjectEntry $reflection): ?CfgType
    {
        return self::withImplicitNullableParamReflectionType(
            $ctx,
            $reflection,
            self::declaredParamTypeForReflectionRaw($ctx, $reflection)
        );
    }

    /** Raw declared type before implicit-nullable Reflection normalization (#26469). */
    private static function declaredParamTypeForReflectionRaw(Context $ctx, ObjectEntry $reflection): ?CfgType
    {
        $className = self::parameterDeclaringClassNameOrNull($reflection);
        if (null !== $className) {
            $entry = VmReflection::resolveClassEntry($ctx, $className);
            if (null !== $entry) {
                $methodLc = strtolower(self::methodNameFromReflection($reflection));
                $params = $entry->methodParameterMetadata[$methodLc] ?? [];
                $index = self::parameterIndexForReflection($reflection);
                $meta = $params[$index] ?? null;
                if (null !== $meta) {
                    // Present metadata wins over InternalArgInfo — null/'' typeString means
                    // untyped stub arginfo (SplFixedArray $index, SplObjectStorage $object; #25856).
                    if (null !== $meta->typeString && '' !== $meta->typeString) {
                        return ReflectionTypeSupport::cfgTypeFromLabel($meta->typeString);
                    }

                    return null;
                }
            }
        }
        if (self::parameterIsInternal($ctx, $reflection)) {
            return self::internalDeclaredParamType($reflection);
        }
        $methodNameVar = $reflection->getProperty(self::PROP_METHOD_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING === $methodNameVar->type) {
            $className = self::classNameFromReflection($reflection);
            $methodName = $methodNameVar->toString();
            $entry = VmReflection::resolveClassEntry($ctx, $className);
            if (null === $entry) {
                return null;
            }
            $methodLc = strtolower($methodName);
            $func = $entry->methods[$methodLc] ?? null;
            if (!$func instanceof PhpFunc) {
                return null;
            }
            $index = self::paramPositionFromReflection($reflection);
            $slot = self::parameterScopeSlot($func->block, $index);

            return self::userDeclaredParamTypeOrNull(
                null !== $slot ? ($func->block->paramDeclaredTypes[$slot] ?? null) : null
            );
        }

        $func = self::resolveFunctionForReflectionParameter($ctx, $reflection);
        $index = self::paramIndexFromReflection($reflection);
        $slot = self::parameterScopeSlot($func->block, $index);

        return self::userDeclaredParamTypeOrNull(
            null !== $slot ? ($func->block->paramDeclaredTypes[$slot] ?? null) : null
        );
    }

    /**
     * php-src zim_reflection_parameter_getType — typed param with `= null` default is nullable
     * even when the AST/CFG type was not written as {@see CfgType\Nullable} (#26469).
     */
    private static function withImplicitNullableParamReflectionType(
        Context $ctx,
        ObjectEntry $reflection,
        ?CfgType $type,
    ): ?CfgType {
        if (null === $type || $type instanceof CfgType\Nullable) {
            return $type;
        }
        if (ReflectionTypeSupport::allowsNullFromCfg($type)) {
            return $type;
        }
        if (!self::userParamIsImplicitNullable($ctx, $reflection)) {
            return $type;
        }

        return new CfgType\Nullable($type);
    }

    /**
     * True when compile marked the parameter scope slot as implicit nullable (#4449 / #26469).
     */
    private static function userParamIsImplicitNullable(Context $ctx, ObjectEntry $reflection): bool
    {
        if (self::parameterIsInternal($ctx, $reflection)) {
            return false;
        }
        try {
            $block = self::resolveParameterBlock($ctx, $reflection);
        } catch (\Throwable) {
            return false;
        }
        $slot = self::parameterScopeSlot($block, self::parameterIndexForReflection($reflection));

        return null !== $slot && isset($block->paramImplicitNullable[$slot]);
    }

    /**
     * php-cfg maps absent parameter types to {@see CfgType\Mixed_}; explicit `mixed` is Literal (#22064).
     */
    private static function userDeclaredParamTypeOrNull(?CfgType $type): ?CfgType
    {
        if (null === $type || $type instanceof CfgType\Mixed_) {
            return null;
        }

        return $type;
    }

    private static function internalDeclaredParamType(ObjectEntry $reflection): ?CfgType
    {
        $index = self::parameterIndexForReflection($reflection);
        $classNameVar = $reflection->getProperty(self::PROP_PARAM_CLASS)->resolveIndirect();
        if (Variable::TYPE_STRING === $classNameVar->type) {
            $info = BuiltinInternalArgInfo::paramInfoForClassMethod(
                $classNameVar->toString(),
                self::methodNameFromReflection($reflection),
                $index
            );
        } else {
            $info = BuiltinInternalArgInfo::paramInfoForFunction(
                self::functionNameFromReflection($reflection),
                $index
            );
        }
        if (null === $info || '' === trim($info['type'])) {
            return null;
        }

        return ReflectionTypeSupport::cfgTypeFromLabel($info['type']);
    }

    public static function parameterIsInternal(Context $ctx, ObjectEntry $reflection): bool
    {
        $classNameVar = $reflection->getProperty(self::PROP_PARAM_CLASS)->resolveIndirect();
        if (Variable::TYPE_STRING === $classNameVar->type) {
            $entry = VmReflection::resolveClassEntry($ctx, $classNameVar->toString());

            return null !== $entry && $entry->isInternal;
        }
        $funcName = self::functionNameFromReflection($reflection);
        $func = $ctx->functions[strtolower($funcName)] ?? null;

        return $func instanceof Func\Internal;
    }

    private static function internalParameterIsOptional(Context $ctx, ObjectEntry $reflection): bool
    {
        $info = self::internalParameterInfo($ctx, $reflection);
        if (null !== $info) {
            return $info['isOptional'];
        }
        $index = self::parameterIndexForReflection($reflection);
        $funcName = self::internalCallableName($ctx, $reflection);
        $variadic = BuiltinParamNames::variadicParamIndexForFunction($funcName);

        return null !== $variadic && $variadic === $index;
    }

    private static function internalParameterDefaultValueIsAvailable(
        Context $ctx,
        ObjectEntry $reflection,
    ): bool {
        $index = self::parameterIndexForReflection($reflection);
        $callableLc = strtolower(self::internalCallableName($ctx, $reflection));
        $info = self::internalParameterInfo($ctx, $reflection);

        return BuiltinInternalDefaultValues::isAvailable(
            $callableLc,
            $index,
            $info,
            self::internalParameterIsVariadic($ctx, $reflection, $callableLc, $index),
        );
    }

    private static function copyInternalParameterDefaultValue(
        Variable $dest,
        Context $ctx,
        ObjectEntry $reflection,
    ): bool {
        $index = self::parameterIndexForReflection($reflection);
        $callableLc = strtolower(self::internalCallableName($ctx, $reflection));
        $info = self::internalParameterInfo($ctx, $reflection);

        return BuiltinInternalDefaultValues::materialize($dest, $callableLc, $index, $info, $ctx);
    }

    private static function internalParameterIsVariadic(
        Context $ctx,
        ObjectEntry $reflection,
        string $callableLc,
        int $index,
    ): bool {
        if (str_contains($callableLc, '::')) {
            // Prefer BuiltinParamNames stub override (Closure::call ...args, #24591).
            $variadic = BuiltinParamNames::variadicParamIndexForFunction($callableLc);
            if (null !== $variadic) {
                return $variadic === $index;
            }
            [$class, $method] = explode('::', $callableLc, 2);
            if (!BuiltinInternalArgInfo::methodIsVariadic($class, $method)) {
                return false;
            }
            $count = BuiltinInternalArgInfo::paramCountForClassMethod($class, $method) ?? 0;

            return $count > 0 && $index === $count - 1;
        }
        $variadic = BuiltinParamNames::variadicParamIndexForFunction($callableLc);

        return null !== $variadic && $variadic === $index;
    }

    private static function internalParameterAllowsNull(Context $ctx, ObjectEntry $reflection): bool
    {
        $info = self::internalParameterInfo($ctx, $reflection);
        if (null === $info) {
            return true;
        }

        return BuiltinInternalArgInfo::typeStringAllowsNull($info['type']);
    }

    private static function internalParameterIsPassedByReference(Context $ctx, ObjectEntry $reflection): bool
    {
        $index = self::parameterIndexForReflection($reflection);
        // Prefer ClassEntry methodParameterMetadata.byRef (php_user_filter::$consumed; #25584).
        $className = self::parameterDeclaringClassNameOrNull($reflection);
        if (null !== $className) {
            $entry = VmReflection::resolveClassEntry($ctx, $className);
            if (null !== $entry) {
                $methodLc = strtolower(self::methodNameFromReflection($reflection));
                $meta = $entry->methodParameterMetadata[$methodLc][$index] ?? null;
                if (null !== $meta) {
                    return $meta->byRef;
                }
            }
        }
        $callable = self::internalCallableName($ctx, $reflection);
        $lc = strtolower($callable);
        if (str_contains($lc, '::')) {
            // BuiltinParamNames may mark by-ref with a leading '&' (Spoofchecker #25055).
            $override = BuiltinParamNames::forClassMethod($lc);
            if (null !== $override && isset($override[$index]) && str_starts_with($override[$index], '&')) {
                return true;
            }
            $byRefs = BuiltinByRefParams::forFunction($lc);
            // Instance call args include $this at index 0; Reflection params do not (#25055).
            if (\in_array($index + 1, $byRefs, true)) {
                return true;
            }

            // Static methods (no $this slot) keep BuiltinByRefParams indices as-is.
            return \in_array($index, $byRefs, true);
        }

        return \in_array($index, BuiltinByRefParams::forFunction($lc), true);
    }

    /**
     * @return array{name: string, type: string, isOptional: bool}|null
     */
    /**
     * @return array{name: string, type: string, isOptional: bool}|null
     */
    public static function internalParameterInfoForReflection(Context $ctx, ObjectEntry $reflection): ?array
    {
        $index = self::parameterIndexForReflection($reflection);
        $classNameVar = $reflection->getProperty(self::PROP_PARAM_CLASS)->resolveIndirect();
        if (Variable::TYPE_STRING === $classNameVar->type) {
            $className = $classNameVar->toString();
            $methodName = self::methodNameFromReflection($reflection);
            $override = BuiltinParamNames::forClassMethod(
                strtolower($className).'::'.strtolower($methodName)
            );
            if (null !== $override && isset($override[$index])) {
                $info = BuiltinInternalArgInfo::paramInfoForClassMethod($className, $methodName, $index);
                $name = rtrim(ltrim($override[$index], '&'), '=');
                if (str_starts_with($name, '...')) {
                    $name = substr($name, 3);
                }
                $optionalFromOverride = BuiltinParamNames::overrideEntryIsOptional($override[$index]);
                if (null !== $info) {
                    // When the stub table encodes `=`, trust it over InternalArgInfo optionality (#25147).
                    $isOptional = BuiltinParamNames::namesEncodeOptionalParams($override)
                        ? $optionalFromOverride
                        : ($info['isOptional'] || $optionalFromOverride);

                    return [
                        'name' => $name,
                        'type' => $info['type'],
                        'isOptional' => $isOptional,
                    ];
                }

                // No InternalArgInfo row: honor `=` markers on the override table (#24392 / #23391).
                // Stub type overrides still apply when php-types omits the class entirely (#25055).
                $typeOverride = BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod(
                    strtolower($className),
                    strtolower($methodName),
                    $index
                );

                return [
                    'name' => $name,
                    'type' => $typeOverride ?? '',
                    'isOptional' => $optionalFromOverride,
                ];
            }

            return BuiltinInternalArgInfo::paramInfoForClassMethod($className, $methodName, $index);
        }
        $functionName = self::functionNameFromReflection($reflection);
        $override = BuiltinParamNames::forFunction($functionName);
        if (null !== $override && isset($override[$index])) {
            $name = rtrim(ltrim($override[$index], '&'), '=');
            if (str_starts_with($name, '...')) {
                $name = substr($name, 3);
            }
            $optionalFromOverride = BuiltinParamNames::overrideEntryIsOptional($override[$index]);
            $info = BuiltinInternalArgInfo::paramInfoForFunction($functionName, $index);
            if (null !== $info) {
                // When the stub table encodes `=`, trust it over InternalArgInfo optionality (#25147).
                $isOptional = BuiltinParamNames::namesEncodeOptionalParams($override)
                    ? $optionalFromOverride
                    : ($info['isOptional'] || $optionalFromOverride);

                return [
                    'name' => $name,
                    'type' => $info['type'],
                    'isOptional' => $isOptional,
                ];
            }

            // No InternalArgInfo row (missing builtin or trailing stub-only param): honor `=` on the
            // override table — do not mark required stub params optional (#24392 gzputs).
            // Still apply stubParamTypeOverride for trailing stub params (#23587 flags).
            $type = '';
            $typeOverride = BuiltinInternalArgInfo::stubParamTypeOverride(strtolower($functionName), $index);
            if (null !== $typeOverride) {
                $type = $typeOverride;
            }

            return [
                'name' => $name,
                'type' => $type,
                'isOptional' => $optionalFromOverride,
            ];
        }

        return BuiltinInternalArgInfo::paramInfoForFunction($functionName, $index);
    }

    public static function reflectedMethodParameterCount(
        Context $ctx,
        string $className,
        string $methodName
    ): int {
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            return 0;
        }
        $methodLc = strtolower($methodName);
        $count = \count($entry->methodParameterMetadata[$methodLc] ?? []);
        if ($count > 0) {
            return $count;
        }

        return BuiltinInternalArgInfo::paramCountForClassMethod($className, $methodName) ?? 0;
    }

    public static function reflectedMethodRequiredParameterCount(
        Context $ctx,
        string $className,
        string $methodName
    ): int {
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            return 0;
        }
        $methodLc = strtolower($methodName);
        $params = $entry->methodParameterMetadata[$methodLc] ?? [];
        if ([] !== $params) {
            $required = 0;
            foreach ($params as $meta) {
                if (!$meta->isOptional && !$meta->isVariadic) {
                    ++$required;
                }
            }

            return $required;
        }

        return BuiltinParamNames::requiredParamCountForInternalMethod($className, $methodName) ?? 0;
    }

    /**
     * @return list<string>
     */
    public static function reflectedMethodParameterNames(
        Context $ctx,
        string $className,
        string $methodName
    ): array {
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            return [];
        }
        $methodLc = strtolower($methodName);
        $params = $entry->methodParameterMetadata[$methodLc] ?? [];
        if ([] !== $params) {
            return array_map(static fn ($meta) => $meta->name, $params);
        }
        $qualified = strtolower($className).'::'.strtolower($methodName);
        $override = BuiltinParamNames::forClassMethod($qualified);
        if (null !== $override) {
            // Strip by-ref / variadic / optional markers — dump and synthesize use bare names (#26223).
            return array_map(static function (string $name): string {
                $n = ltrim($name, '&');
                if (str_starts_with($n, '...')) {
                    $n = substr($n, 3);
                }

                return rtrim($n, '=');
            }, $override);
        }
        $count = BuiltinInternalArgInfo::paramCountForClassMethod($className, $methodName) ?? 0;
        $names = [];
        for ($i = 0; $i < $count; ++$i) {
            $info = BuiltinInternalArgInfo::paramInfoForClassMethod($className, $methodName, $i);
            $names[] = $info['name'] ?? 'param'.$i;
        }

        return $names;
    }

    private static function internalParameterInfo(Context $ctx, ObjectEntry $reflection): ?array
    {
        return self::internalParameterInfoForReflection($ctx, $reflection);
    }

    private static function internalCallableName(Context $ctx, ObjectEntry $reflection): string
    {
        $classNameVar = $reflection->getProperty(self::PROP_PARAM_CLASS)->resolveIndirect();
        if (Variable::TYPE_STRING === $classNameVar->type) {
            return $classNameVar->toString().'::'.self::methodNameFromReflection($reflection);
        }

        return self::functionNameFromReflection($reflection);
    }

    /**
     * ReflectionFunction::getNumberOfParameters() — named, internal, or Closure (#25559).
     *
     * php-src: zim_ReflectionFunctionAbstract_getNumberOfParameters — fake closures keep
     * the underlying zend_function arg_info (Zend/zend_closures.c).
     */
    public static function functionNumberOfParameters(Context $ctx, ObjectEntry $reflection): int
    {
        return \count(self::functionParameterNames($ctx, $reflection));
    }

    /**
     * ReflectionFunction::getNumberOfRequiredParameters() (#25559).
     */
    public static function functionNumberOfRequiredParameters(Context $ctx, ObjectEntry $reflection): int
    {
        if (self::isReflectionInternalFunction($reflection)) {
            $funcName = self::functionNameFromReflection($reflection);

            return BuiltinParamNames::requiredParamCountForInternalFunction($funcName) ?? 0;
        }
        $state = $reflection->reflectionClosureState;
        if (null !== $state) {
            return self::closureNumberOfRequiredParameters($ctx, $state);
        }
        $func = self::resolveUserFunction($ctx, self::functionNameFromReflection($reflection));

        return self::requiredParameterCountFromBlock($func->block);
    }

    /**
     * Parameter names for ReflectionFunction::getParameters() (#25559).
     *
     * @return list<string>
     */
    public static function functionParameterNames(Context $ctx, ObjectEntry $reflection): array
    {
        if (self::isReflectionInternalFunction($reflection)) {
            $funcName = self::functionNameFromReflection($reflection);

            return BuiltinParamNames::paramNamesForInternalFunction($funcName) ?? [];
        }
        $state = $reflection->reflectionClosureState;
        if (null !== $state) {
            return self::closureParameterNames($ctx, $state);
        }
        $func = self::resolveUserFunction($ctx, self::functionNameFromReflection($reflection));

        return array_values($func->block->paramNames);
    }

    /**
     * @return list<string>
     */
    private static function closureParameterNames(Context $ctx, ClosureState $state): array
    {
        $underlying = $state->wrappedFunc ?? $state->func;
        if ($underlying instanceof PhpFunc) {
            return array_values($underlying->block->paramNames);
        }
        [$className, $methodName] = self::methodScopeAndNameFromClosureState($ctx, $state);
        if (null !== $className && '' !== $className && null !== $methodName && '' !== $methodName) {
            $entry = VmReflection::resolveClassEntry($ctx, $className);
            if (null !== $entry) {
                return array_values(self::methodParameterNames($entry, $methodName));
            }
        }
        if ($underlying instanceof Func\Internal) {
            return BuiltinParamNames::paramNamesForInternalFunction($underlying->getName()) ?? [];
        }

        return array_values($state->func->block->paramNames);
    }

    private static function closureNumberOfRequiredParameters(Context $ctx, ClosureState $state): int
    {
        $underlying = $state->wrappedFunc ?? $state->func;
        if ($underlying instanceof PhpFunc) {
            return self::requiredParameterCountFromBlock($underlying->block);
        }
        [$className, $methodName] = self::methodScopeAndNameFromClosureState($ctx, $state);
        if (null !== $className && '' !== $className && null !== $methodName && '' !== $methodName) {
            $entry = VmReflection::resolveClassEntry($ctx, $className);
            if (null !== $entry) {
                return self::methodNumberOfRequiredParameters($entry, $methodName);
            }
        }
        if ($underlying instanceof Func\Internal) {
            return BuiltinParamNames::requiredParamCountForInternalFunction($underlying->getName()) ?? 0;
        }

        return self::requiredParameterCountFromBlock($state->func->block);
    }

    public static function methodNumberOfParameters(ClassEntry $entry, string $method): int
    {
        $methodLc = strtolower($method);
        $params = $entry->methodParameterMetadata[$methodLc] ?? [];
        if ([] !== $params) {
            return \count($params);
        }
        if ($entry->isInternal) {
            return BuiltinParamNames::paramCountForInternalMethod($entry->name, $method) ?? 0;
        }
        $func = $entry->methods[$methodLc] ?? null;
        if ($func instanceof PhpFunc) {
            return \count($func->block->paramNames);
        }

        return BuiltinParamNames::paramCountForInternalMethod($entry->name, $method) ?? 0;
    }

    public static function methodNumberOfRequiredParameters(ClassEntry $entry, string $method): int
    {
        $methodLc = strtolower($method);
        $params = $entry->methodParameterMetadata[$methodLc] ?? [];
        if ([] !== $params) {
            return self::requiredParameterCountFromBlock(
                self::resolveMethodBlock($entry, $methodLc)
            );
        }
        if ($entry->isInternal) {
            return BuiltinParamNames::requiredParamCountForInternalMethod($entry->name, $method) ?? 0;
        }
        $func = $entry->methods[$methodLc] ?? null;
        if ($func instanceof PhpFunc) {
            return self::requiredParameterCountFromBlock($func->block);
        }

        return BuiltinParamNames::requiredParamCountForInternalMethod($entry->name, $method) ?? 0;
    }

    /**
     * @return list<string>
     */
    public static function methodParameterNames(ClassEntry $entry, string $method): array
    {
        $methodLc = strtolower($method);
        $params = $entry->methodParameterMetadata[$methodLc] ?? [];
        if ([] !== $params) {
            return array_map(static fn ($meta) => $meta->name, $params);
        }
        $override = BuiltinParamNames::forClassMethod(strtolower($entry->name).'::'.$methodLc);
        if (null !== $override) {
            return array_map(static function (string $name): string {
                $n = ltrim($name, '&');
                if (str_starts_with($n, '...')) {
                    $n = substr($n, 3);
                }

                return rtrim($n, '=');
            }, $override);
        }
        if ($entry->isInternal) {
            return BuiltinInternalArgInfo::paramNamesForClassMethod($entry->name, $method);
        }
        $func = $entry->methods[$methodLc] ?? null;
        if ($func instanceof PhpFunc) {
            return $func->block->paramNames;
        }

        return BuiltinInternalArgInfo::paramNamesForClassMethod($entry->name, $method);
    }

    private static function resolveMethodBlock(ClassEntry $entry, string $methodLc): Block
    {
        $func = $entry->methods[$methodLc] ?? null;
        if (!$func instanceof PhpFunc) {
            throw new \LogicException('ReflectionMethod refers to unknown method in this compiler build');
        }

        return $func->block;
    }

    private static function requiredParameterCountFromBlock(Block $block): int
    {
        $required = 0;
        for ($i = 0, $n = \count($block->paramNames); $i < $n; ++$i) {
            if (self::parameterIsVariadic($block, $i)
                || ParamArgumentCountError::parameterHasDefault($block, $i)
            ) {
                break;
            }
            ++$required;
        }

        return $required;
    }

    public static function parameterDefaultValueIsAvailable(\PHPCompiler\Block $block, int $paramIndex): bool
    {
        if (self::parameterIsVariadic($block, $paramIndex)) {
            return false;
        }

        return ParamArgumentCountError::parameterHasDefault($block, $paramIndex);
    }

    public static function parameterScopeSlot(\PHPCompiler\Block $block, int $paramIndex): ?int
    {
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV !== $op->type || (int) $op->arg2 !== $paramIndex) {
                continue;
            }

            return (int) $op->arg1;
        }

        return null;
    }

    /**
     * @return \PHPCompiler\Func\PHP
     */
    public static function resolveUserFunction(Context $ctx, string $functionName): \PHPCompiler\Func\PHP
    {
        $lc = strtolower($functionName);
        $func = $ctx->functions[$lc] ?? null;
        if (!$func instanceof \PHPCompiler\Func\PHP) {
            self::throwReflectionException(self::functionNotFoundMessage($functionName));
        }

        return $func;
    }

    /**
     * Resolve declaring function for ReflectionParameter on named or closure functions (#11545).
     *
     * @return \PHPCompiler\Func\PHP
     */
    public static function resolveFunctionForReflectionParameter(Context $ctx, ObjectEntry $parameter): \PHPCompiler\Func\PHP
    {
        $closure = $parameter->reflectionClosureState;
        if (null !== $closure) {
            $underlying = $closure->wrappedFunc ?? $closure->func;
            if ($underlying instanceof PhpFunc) {
                return $underlying;
            }

            return $closure->func;
        }

        return self::resolveUserFunction($ctx, self::functionNameFromReflection($parameter));
    }

    public static function typeStringFromReflection(ObjectEntry $reflection): string
    {
        $stored = $reflection->getProperty(self::PROP_TYPE_STRING)->resolveIndirect();
        if (Variable::TYPE_STRING !== $stored->type) {
            throw new \LogicException('ReflectionType missing type string');
        }

        return $stored->toString();
    }

    public static function allowsNullFromReflection(ObjectEntry $reflection): bool
    {
        $flag = $reflection->getProperty(self::PROP_TYPE_ALLOWS_NULL)->resolveIndirect();

        return $flag->toBool();
    }

    /**
     * Declaring class + lowercase method name for a ReflectionMethod receiver (#7116).
     *
     * @return array{0: ClassEntry, 1: string}
     */
    public static function resolveReflectedMethodDeclaring(Context $ctx, ObjectEntry $reflection): array
    {
        $className = self::classNameFromReflection($reflection);
        $methodName = self::methodNameFromReflection($reflection);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            self::throwReflectionException(self::classNotFoundMessage($className));
        }
        $methodLc = strtolower($methodName);
        $lcClass = strtolower($entry->name);
        $visited = [];
        while (!isset($visited[$lcClass])) {
            $visited[$lcClass] = true;
            if (!isset($ctx->classes[$lcClass])) {
                break;
            }
            $class = $ctx->classes[$lcClass];
            if (isset($class->methods[$methodLc]) || isset($class->abstractMethods[$methodLc])) {
                return [$class, $methodLc];
            }
            if (null === $class->parentLc) {
                break;
            }
            $lcClass = $class->parentLc;
        }

        self::throwReflectionException(self::methodNotFoundMessage($entry->name, $methodName));
    }

    /** PHPCfg method flags for a reflected method (#7116). */
    public static function reflectedMethodCfgFlags(Context $ctx, ObjectEntry $reflection): int
    {
        [$declaring, $methodLc] = self::resolveReflectedMethodDeclaring($ctx, $reflection);
        $flags = $declaring->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
        if (isset($declaring->abstractMethods[$methodLc])) {
            $flags |= \PHPCfg\Func::FLAG_ABSTRACT;
        }

        return $flags;
    }

    /** php-src reflection_*_set_accessible — stores override on reflection object (#9823). */
    public static function setReflectionAccessible(ObjectEntry $reflection, bool $accessible): void
    {
        $reflection->getProperty(self::PROP_ACCESSIBLE)->bool($accessible);
    }

    private static function reflectionAccessibleForced(ObjectEntry $reflection): bool
    {
        $slot = $reflection->getProperty(self::PROP_ACCESSIBLE);
        if ($slot->isUndefined()) {
            return false;
        }
        $resolved = $slot->resolveIndirect();
        if (Variable::TYPE_BOOLEAN !== $resolved->type) {
            return false;
        }

        return $resolved->toBool();
    }

    /** php-src reflection_method_is_accessible (#9823). */
    public static function isReflectionMethodAccessible(Context $ctx, ObjectEntry $reflection): bool
    {
        $flags = self::reflectedMethodCfgFlags($ctx, $reflection);
        if (MethodVisibility::isPublic($flags)) {
            return true;
        }

        return self::reflectionAccessibleForced($reflection);
    }

    /**
     * php-src 8.1+: ReflectionMethod::invoke() / invokeArgs() ignore accessible (#22090, re-#9823).
     */
    public static function assertReflectionMethodAccessible(Context $ctx, ObjectEntry $reflection): void
    {
    }

    /** php-src reflection_property_is_accessible (#9823). */
    public static function isReflectionPropertyAccessible(Context $ctx, ObjectEntry $reflection): bool
    {
        $className = self::classNameFromReflection($reflection);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            return false;
        }
        $property = self::propertyNameFromReflection($reflection);
        if (VmReflection::isEnumReflectionPseudoProperty($entry, $property)
            || self::isDynamicReflectionProperty($reflection)
        ) {
            return true;
        }
        $visibilityMeta = VmReflection::propertyVisibilityMeta($entry, $property, $ctx);
        if (null === $visibilityMeta) {
            return false;
        }
        if (MethodVisibility::isPublic($visibilityMeta['visibility'])) {
            return true;
        }

        return self::reflectionAccessibleForced($reflection);
    }

    /**
     * php-src 8.1+: ReflectionProperty::getValue() / setValue() ignore accessible (#22091, re-#9823).
     */
    public static function assertReflectionPropertyAccessible(Context $ctx, ObjectEntry $reflection): void
    {
    }

    /** php-src reflection_function_is_accessible — global functions always accessible (#9823). */
    public static function isReflectionFunctionAccessible(): bool
    {
        return true;
    }

    /**
     * Whether compile-time metadata includes a user-declared return type (#5141).
     *
     * php-src: reflection_function_has_return_type() ignores ZEND_TYPE_IS_TENTATIVE;
     * php-cfg maps absent return types to {@see CfgType\Mixed_}.
     */
    public static function hasDeclaredReturnType(?CfgType $type): bool
    {
        if (null === $type) {
            return false;
        }

        return !$type instanceof CfgType\Mixed_;
    }

    /** php-src: reflection_method_is_internal() (#18228). */
    public static function isReflectionMethodInternal(Context $ctx, ObjectEntry $reflection): bool
    {
        [$declaring, $methodLc] = self::resolveReflectedMethodDeclaring($ctx, $reflection);
        if (!isset($declaring->methods[$methodLc])) {
            return false;
        }
        $func = $declaring->methods[$methodLc];

        return !($func instanceof PhpFunc);
    }

    /** php-src: reflection_method_is_variadic() (#18228). */
    public static function isReflectionMethodVariadic(Context $ctx, ObjectEntry $reflection): bool
    {
        [$declaring, $methodLc] = self::resolveReflectedMethodDeclaring($ctx, $reflection);
        if (isset($declaring->methods[$methodLc])) {
            $func = $declaring->methods[$methodLc];
            if ($func instanceof PhpFunc) {
                return null !== $func->block->variadicParamIndex;
            }
        }
        $className = self::classNameFromReflection($reflection);
        $methodName = self::methodNameFromReflection($reflection);
        $qualified = strtolower($className).'::'.strtolower($methodName);
        if (null !== BuiltinParamNames::variadicParamIndexForFunction($qualified)) {
            return true;
        }

        return BuiltinInternalArgInfo::methodIsVariadic($className, $methodName);
    }

    /** php-src: reflection_method_is_constructor() (#18225). */
    public static function isReflectionMethodConstructor(ObjectEntry $reflection): bool
    {
        return '__construct' === strtolower(self::methodNameFromReflection($reflection));
    }

    /** php-src: reflection_method_is_destructor() (#18225). */
    public static function isReflectionMethodDestructor(ObjectEntry $reflection): bool
    {
        return '__destruct' === strtolower(self::methodNameFromReflection($reflection));
    }

    /** php-src: reflection_method_is_abstract() (#18225). */
    public static function isReflectionMethodAbstract(Context $ctx, ObjectEntry $reflection): bool
    {
        [$declaring] = self::resolveReflectedMethodDeclaring($ctx, $reflection);
        if ($declaring->isInterface) {
            return true;
        }
        $flags = self::reflectedMethodCfgFlags($ctx, $reflection);

        return ($flags & \PHPCfg\Func::FLAG_ABSTRACT) !== 0;
    }

    /**
     * php-src: reflection_function_get_return_type() for internals (#22068, #25043).
     *
     * Stub return labels come from php-types arginfo via {@see BuiltinInternalArgInfo}.
     */
    public static function reflectedFunctionInternalReturnType(ObjectEntry $reflection): ?CfgType
    {
        $label = BuiltinInternalArgInfo::returnTypeLabelForFunction(
            self::functionNameFromReflection($reflection)
        );
        if (null === $label) {
            return null;
        }

        return ReflectionTypeSupport::cfgTypeFromLabel($label);
    }

    /** php-src: reflection_function_has_return_type() for internals (#22068, #25043). */
    public static function reflectedFunctionHasInternalReturnType(ObjectEntry $reflection): bool
    {
        return null !== BuiltinInternalArgInfo::returnTypeLabelForFunction(
            self::functionNameFromReflection($reflection)
        );
    }

    /**
     * php-src: reflection_function_get_tentative_return_type() (#22169).
     *
     * Tentative returns are ZEND_ACC_TENTATIVE_RETURN on internal *class methods* only;
     * free functions / closures / user funcs report null (php-src tables + Zend 8.2).
     */
    public static function reflectedFunctionTentativeReturnType(ObjectEntry $reflection): ?CfgType
    {
        return null;
    }

    /**
     * php-src: reflection_function_has_tentative_return_type() (#22169).
     */
    public static function reflectedFunctionHasTentativeReturnType(ObjectEntry $reflection): bool
    {
        return null !== self::reflectedFunctionTentativeReturnType($reflection);
    }

    /**
     * php-src: reflection_method_get_tentative_return_type() (#18226).
     *
     * User-declared methods store explicit return types on the declaring Func; tentative
     * inheritance is not modeled yet — null for VM user methods.
     */
    public static function reflectedMethodTentativeReturnType(Context $ctx, ObjectEntry $reflection): ?CfgType
    {
        [$declaring, $methodLc] = self::resolveReflectedMethodDeclaring($ctx, $reflection);
        if (isset($declaring->methods[$methodLc])) {
            $func = $declaring->methods[$methodLc];
            if ($func instanceof PhpFunc) {
                return null;
            }
        }
        $className = self::classNameFromReflection($reflection);
        $methodName = self::methodNameFromReflection($reflection);
        $label = BuiltinInternalArgInfo::tentativeReturnTypeForClassMethod($className, $methodName);
        if (null === $label) {
            return null;
        }

        return ReflectionTypeSupport::cfgTypeFromLabel($label);
    }

    /**
     * php-src: reflection_method_has_tentative_return_type() (#6597, #18226).
     */
    public static function reflectedMethodHasTentativeReturnType(Context $ctx, ObjectEntry $reflection): bool
    {
        return null !== self::reflectedMethodTentativeReturnType($ctx, $reflection);
    }

    /**
     * @return array{0: ClassEntry, 1: string, 2: Func}
     */
    public static function resolveReflectedMethod(Context $ctx, ObjectEntry $reflection): array
    {
        [$class, $methodLc] = self::resolveReflectedMethodDeclaring($ctx, $reflection);
        if (!isset($class->methods[$methodLc])) {
            $className = self::classNameFromReflection($reflection);
            $methodName = self::methodNameFromReflection($reflection);
            self::throwReflectionException(self::methodNotFoundMessage($className, $methodName));
        }

        return [$class, $methodLc, $class->methods[$methodLc]];
    }

    /**
     * @param array<int, Variable> $invokeArgs possibly sparse (named optionals, #23388)
     */
    public static function invokeReflectedMethod(
        VmEngine $vm,
        Frame $frame,
        ObjectEntry $reflection,
        Variable $objectArg,
        array $invokeArgs
    ): Variable {
        $ctx = VmReflection::requireContext($frame);
        self::assertReflectionMethodAccessible($ctx, $reflection);
        [$declaring, $methodLc, $func] = self::resolveReflectedMethod($ctx, $reflection);
        $methodName = $declaring->methodNames[$methodLc] ?? self::methodNameFromReflection($reflection);
        $isStatic = ($func instanceof Func\PHP && self::methodIsStatic($func))
            || ($func instanceof Func\Internal
                && (self::reflectedMethodCfgFlags($ctx, $reflection) & \PHPCfg\Func::FLAG_STATIC) !== 0);
        if ($isStatic) {
            return $vm->invokeDeclaredStaticWithCalledArgs(
                $declaring->name,
                $declaring->name,
                $methodName,
                $invokeArgs
            );
        }
        $objectArg = $objectArg->resolveIndirect();
        if (Variable::TYPE_NULL === $objectArg->type) {
            self::throwReflectionException(
                'Trying to invoke non static method '.$declaring->name.'::'.$methodName.'() without an object'
            );
        }
        if (Variable::TYPE_OBJECT !== $objectArg->type) {
            throw new \TypeError(
                'ReflectionMethod::invoke(): Argument #1 ($object) must be of type ?object, '
                .self::valueTypeLabel($objectArg).' given'
            );
        }
        if (!VmReflection::isInstanceOfObject($ctx, $objectArg, $declaring->name)) {
            self::throwReflectionException(
                'Given object is not an instance of the class this method was declared in'
            );
        }
        if ($func instanceof Func\Internal) {
            return $vm->invokeInstanceMethod(
                $objectArg->toObject(),
                $methodName,
                ...array_values($invokeArgs)
            );
        }
        if (!$func instanceof Func\PHP) {
            throw new \LogicException("{$declaring->name}::{$methodName}() is not a user method in this compiler build");
        }

        $thisVar = new Variable();
        $thisVar->object($objectArg->toObject());
        // ARG_RECV shifts instance method indices by +1 for $this (see VM TYPE_ARG_RECV).
        $calledArgs = [0 => $thisVar];
        foreach ($invokeArgs as $idx => $value) {
            $calledArgs[1 + (int) $idx] = $value;
        }

        return $vm->invokePhpFunctionIsolatedWithCalledArgs($func, $calledArgs);
    }

    /**
     * Collect Reflection*::invoke() trailing args, expanding named variadic packs (#24949).
     *
     * Z_PARAM_VARIADIC_WITH_NAMED packs unknown names into one array (call_user_func shape);
     * resolve those entries against the reflected callee like invokeArgs / zend_call_function.
     *
     * @param list<string> $paramNames
     *
     * @return array<int, Variable>
     */
    public static function invokeTrailingArgsFromCalledArgs(
        Frame $frame,
        int $firstTrailingIndex,
        array $paramNames,
        ?int $variadicParamIndex,
        ?string $functionName
    ): array {
        $argc = \count($frame->calledArgs);
        if ($argc <= $firstTrailingIndex) {
            return [];
        }
        $trailingCount = $argc - $firstTrailingIndex;
        if (1 === $trailingCount) {
            $sole = $frame->calledArgs[$firstTrailingIndex]->resolveIndirect();
            if (Variable::TYPE_ARRAY === $sole->type && self::invokeArrayArgShouldUnpack($sole)) {
                return self::invokeArgsFromArray(
                    $sole,
                    'Reflection::invoke',
                    1,
                    $paramNames,
                    $variadicParamIndex,
                    $functionName
                );
            }
        }
        $entries = [];
        for ($i = $firstTrailingIndex; $i < $argc; ++$i) {
            $copy = new Variable();
            $copy->copyFrom($frame->calledArgs[$i]->resolveIndirect());
            $entries[] = ['p', $copy];
        }
        $resolved = NamedArgs::resolve($entries, $paramNames, $variadicParamIndex, $functionName);
        ksort($resolved);

        return $resolved;
    }

    /** Named-arg lowering packs string keys; list arrays are single value args (#24949 / #14829). */
    private static function invokeArrayArgShouldUnpack(Variable $arrayVar): bool
    {
        if ($arrayVar->namedVariadicPack) {
            return true;
        }
        foreach ($arrayVar->toArray()->iterateKeyed(false) as $pair) {
            [$keyVar, ] = $pair;
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_STRING !== $key->type) {
                continue;
            }
            $keyStr = $key->toString();
            if ('' !== $keyStr && !ctype_digit($keyStr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Unpack a Reflection *Args array parameter (php-src-strict Argument #N message).
     *
     * Without param metadata, values are taken in iteration order (legacy / newInstanceArgs).
     * With param names, string keys map to named parameters like zend_call_function (#23388).
     *
     * @param list<string>|null $paramNames
     *
     * @return array<int, Variable>
     */
    public static function invokeArgsFromArray(
        Variable $argsVar,
        string $methodLabel,
        int $argsArgumentNumber = 2,
        ?array $paramNames = null,
        ?int $variadicParamIndex = null,
        ?string $functionName = null
    ): array {
        $argsVar = $argsVar->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $argsVar->type) {
            throw new \TypeError(
                $methodLabel.'(): Argument #'.$argsArgumentNumber.' ($args) must be of type array, '
                .self::valueTypeLabel($argsVar).' given'
            );
        }
        if (null === $paramNames) {
            $invokeArgs = [];
            foreach ($argsVar->toArray()->iterate(true) as $value) {
                $copy = new Variable();
                $copy->copyFrom($value);
                $invokeArgs[] = $copy;
            }

            return $invokeArgs;
        }

        $entries = CallUnpack::expandArrayEntries(
            $argsVar,
            $paramNames,
            $variadicParamIndex,
            $functionName,
            false
        );
        $resolved = NamedArgs::resolve($entries, $paramNames, $variadicParamIndex, $functionName);
        ksort($resolved);

        return $resolved;
    }

    /**
     * Parameter metadata for ReflectionFunction::invokeArgs named-key packing (#23388).
     *
     * @return array{0: list<string>, 1: ?int, 2: ?string}
     */
    public static function functionInvokeParamMetadata(Context $ctx, ObjectEntry $reflection): array
    {
        $closure = $reflection->reflectionClosureState;
        if (null !== $closure) {
            $underlying = $closure->wrappedFunc ?? $closure->func;
            if ($underlying instanceof PhpFunc) {
                $block = $underlying->block;

                return [
                    array_values($block->paramNames),
                    $block->variadicParamIndex,
                    $block->func?->name,
                ];
            }
            $names = self::closureParameterNames($ctx, $closure);

            return [$names, null, self::displayNameForClosureState($closure)];
        }
        $name = self::functionNameFromReflection($reflection);
        if (self::isReflectionInternalFunction($reflection)) {
            return [
                BuiltinParamNames::paramNamesForInternalFunction($name) ?? [],
                BuiltinParamNames::variadicParamIndexForFunction($name),
                $name,
            ];
        }
        $func = self::resolveFunctionFromReflection($ctx, $reflection);

        return [
            array_values($func->block->paramNames),
            $func->block->variadicParamIndex,
            $func->block->func?->name ?? $name,
        ];
    }

    /**
     * Parameter metadata for ReflectionMethod::invokeArgs named-key packing (#23388).
     *
     * @return array{0: list<string>, 1: ?int, 2: ?string}
     */
    public static function methodInvokeParamMetadata(Context $ctx, ObjectEntry $reflection): array
    {
        [$declaring, $methodLc, $func] = self::resolveReflectedMethod($ctx, $reflection);
        $methodName = $declaring->methodNames[$methodLc] ?? self::methodNameFromReflection($reflection);
        $names = self::methodParameterNames($declaring, $methodName);
        $variadic = null;
        $fnName = $declaring->name.'::'.$methodName;
        if ($func instanceof Func\PHP) {
            $variadic = $func->block->variadicParamIndex;
        } else {
            $variadic = BuiltinParamNames::variadicParamIndexForFunction(
                strtolower($declaring->name).'::'.strtolower($methodName)
            );
        }

        return [$names, $variadic, $fnName];
    }

    private static function methodIsStatic(Func $func): bool
    {
        if (!$func instanceof Func\PHP) {
            return false;
        }
        $decl = $func->block->func;

        return null !== $decl && (($decl->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) !== 0;
    }

    /**
     * ReflectionFunctionAbstract::returnsReference() — FLAG_RETURNS_REF (#22171).
     *
     * php-src: ext/reflection/php_reflection.c — zim_ReflectionFunctionAbstract_returnsReference
     */
    public static function functionReturnsReference(Context $ctx, ObjectEntry $reflection): bool
    {
        $state = $reflection->reflectionClosureState;
        if (null !== $state) {
            return self::phpFuncReturnsReference($state->func);
        }
        if ($reflection->reflectionIsInternalFunction) {
            return false;
        }
        $func = $ctx->functions[strtolower(self::functionNameFromReflection($reflection))] ?? null;

        return self::phpFuncReturnsReference($func);
    }

    /**
     * ReflectionMethod::returnsReference() (#22171).
     *
     * Abstract interface stubs (Serializable etc.) live only in abstractMethods — no Func (#25406).
     */
    public static function methodReturnsReference(Context $ctx, ObjectEntry $reflection): bool
    {
        [$declaring, $methodLc] = self::resolveReflectedMethodDeclaring($ctx, $reflection);
        $func = $declaring->methods[$methodLc] ?? null;

        return self::phpFuncReturnsReference($func instanceof Func ? $func : null);
    }

    private static function phpFuncReturnsReference(?Func $func): bool
    {
        if (!$func instanceof Func\PHP) {
            return false;
        }
        $decl = $func->block->func;

        return null !== $decl && (($decl->flags ?? 0) & \PHPCfg\Func::FLAG_RETURNS_REF) !== 0;
    }

    /** php-src ext/reflection/php_reflection.c — ReflectionMethod::hasPrototype (#7262). */
    public static function methodHasPrototype(Context $ctx, ClassEntry $entry, string $methodLc): bool
    {
        return null !== self::methodPrototypeClassEntry($ctx, $entry, $methodLc);
    }

    /**
     * Class entry for ReflectionMethod::getPrototype() or null when none (#7262).
     *
     * Mirrors zend inheritance: child.prototype = parent.prototype ?: parent.
     */
    public static function methodPrototypeClassEntry(Context $ctx, ClassEntry $entry, string $methodLc): ?ClassEntry
    {
        $classLc = strtolower($entry->name);
        $declLc = $entry->methodDeclaringClassLc[$methodLc] ?? $classLc;
        if ($declLc !== $classLc) {
            return null;
        }

        if (null !== $entry->parentLc && isset($ctx->classes[$entry->parentLc])) {
            $parent = $ctx->classes[$entry->parentLc];
            if (isset($parent->methods[$methodLc])) {
                $parentProto = self::methodPrototypeClassEntry($ctx, $parent, $methodLc);
                if (null !== $parentProto) {
                    return $parentProto;
                }
                $parentDeclLc = $parent->methodDeclaringClassLc[$methodLc] ?? strtolower($parent->name);
                $parentVis = $parent->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
                if (($parentVis & \PHPCfg\Func::FLAG_PRIVATE) !== 0
                    && $parentDeclLc === strtolower($parent->name)) {
                    return null;
                }

                return $ctx->classes[$parentDeclLc] ?? null;
            }
        }

        foreach ($entry->interfaces as $ifaceLc) {
            if (!isset($ctx->classes[$ifaceLc])) {
                continue;
            }
            $iface = $ctx->classes[$ifaceLc];
            if (isset($iface->methods[$methodLc]) || isset($iface->abstractMethods[$methodLc])) {
                return $iface;
            }
        }

        return null;
    }

    /**
     * Resolve a class + method pair for ReflectionMethod construction (#3340, #7038).
     *
     * @return array{0: ClassEntry, 1: string}
     */
    public static function reflectionMethodFromClassAndMethod(
        Context $ctx,
        string $className,
        string $methodName
    ): array {
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            self::throwReflectionException(self::classNotFoundMessage($className));
        }
        $methodLc = strtolower($methodName);
        // php-src walks inheritance for ReflectionClass::getMethod() / ReflectionMethod::__construct.
        $declaring = null;
        $chain = $entry->isInterface
            ? VmReflection::interfaceDeclarationChain($entry, $ctx)
            : VmReflection::classHierarchyChain($entry, $ctx);
        foreach ($chain as $class) {
            if (!isset($class->methods[$methodLc]) && !isset($class->abstractMethods[$methodLc])) {
                continue;
            }
            $vis = $class->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
            // php-src: parent-private methods are not visible on the child (#7191),
            // except __construct / __destruct (ce->constructor / ReflectionClass::getMethod; #26059).
            if (($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0 && $class !== $entry
                && '__construct' !== $methodLc && '__destruct' !== $methodLc) {
                continue;
            }
            $declaring = $class;
            break;
        }
        if (null === $declaring) {
            if ($entry->isEnum && VmReflection::methodExistsOnClass($entry, $methodName)) {
                $declaring = $entry;
            } else {
                self::throwReflectionException(self::methodNotFoundMessage($entry->name, $methodName));
            }
        }
        // php-src: ReflectionMethod::$class is the declaring scope ce (#22582), not the
        // class name passed to __construct / ReflectionClass::getMethod().
        $declName = self::declaringClassNameForMethod($ctx, $entry, $methodName);
        $declEntry = $ctx->classes[strtolower($declName)] ?? $declaring;
        // Canonicalize method casing like Zend (DOM appendchild → appendChild; #21283).
        return [$declEntry, VmReflection::canonicalMethodDisplayName($declaring, $methodLc)];
    }

    /**
     * ReflectionMethod::createFromMethodName() — php-src ext/reflection/php_reflection.c (#7038).
     */
    public static function reflectionMethodFromMethodName(Context $ctx, string $classMethod): ObjectEntry
    {
        $sep = strpos($classMethod, '::');
        if (false === $sep) {
            self::throwReflectionException(
                'ReflectionMethod::createFromMethodName(): Argument #1 ($method) must be a valid method name'
            );
        }
        $className = substr($classMethod, 0, $sep);
        $methodName = substr($classMethod, $sep + 2);
        [$entry, $methodName] = self::reflectionMethodFromClassAndMethod($ctx, $className, $methodName);

        return self::newReflectionMethodObject($ctx, $entry, $methodName);
    }

    public static function newReflectionMethodObject(Context $ctx, ClassEntry $entry, string $methodName): ObjectEntry
    {
        $rmClass = $ctx->classes[self::REFLECTION_METHOD] ?? null;
        if (null === $rmClass) {
            throw new \LogicException('ReflectionMethod is not registered in this compiler build');
        }
        $rm = new ObjectEntry($rmClass);
        $rm->constructed = true;
        // Zend public $class = declaring class (#18298, #22582).
        $rm->getProperty(self::PROP_REFLECTION_METHOD_CLASS)->string(
            self::declaringClassNameForMethod($ctx, $entry, $methodName)
        );
        $rm->getProperty(self::PROP_REFLECTION_METHOD_FUNC)->string($methodName);

        return $rm;
    }

    public static function methodSourceLocation(ClassEntry $entry, string $methodLc): ?SourceLocation
    {
        return $entry->methodSourceLocations[$methodLc] ?? null;
    }

    /**
     * ReflectionFunction source metadata — Func\PHP::$sourceLocation or ClosureState (#22144).
     */
    public static function functionSourceLocation(Context $ctx, ObjectEntry $reflection): ?SourceLocation
    {
        $state = $reflection->reflectionClosureState;
        if (null !== $state) {
            $func = $state->func;
            if ($func instanceof Func\PHP && null !== $func->sourceLocation) {
                return $func->sourceLocation;
            }
            if ('' !== $state->definitionFile || $state->definitionLine > 0) {
                return new SourceLocation(
                    null,
                    $state->definitionLine,
                    $state->definitionLine,
                    $state->definitionFile
                );
            }

            return null;
        }
        if ($reflection->reflectionIsInternalFunction) {
            return null;
        }
        $name = self::functionNameFromReflection($reflection);
        $func = $ctx->functions[strtolower($name)] ?? null;
        if ($func instanceof Func\PHP) {
            return $func->sourceLocation;
        }

        return null;
    }

    /**
     * ReflectionFunction::getShortName() — php-src suffix after last backslash; closures → {closure} (#22144).
     */
    public static function functionShortNameFromReflection(ObjectEntry $reflection): string
    {
        $state = $reflection->reflectionClosureState;
        if (null !== $state && $state->isUserClosure()) {
            return '{closure}';
        }

        return self::globalConstantShortName(self::functionNameFromReflection($reflection));
    }

    /**
     * ReflectionFunction::getNamespaceName() — php-src prefix before last backslash (#22144).
     */
    public static function functionNamespaceNameFromReflection(ObjectEntry $reflection): string
    {
        $state = $reflection->reflectionClosureState;
        if (null !== $state && $state->isUserClosure()) {
            // Named display is {anonymous}#N without NS; Zend uses declaring namespace.
            // Prefer Func name when it is Demo\{closure}-shaped; else empty until tracked.
            $name = $state->func->getName();
            if (str_contains($name, '\\')) {
                return self::globalConstantNamespaceName($name);
            }

            return '';
        }

        return self::globalConstantNamespaceName(self::functionNameFromReflection($reflection));
    }

    /** ReflectionFunction::inNamespace() — php-src (#22144). */
    public static function functionInNamespaceFromReflection(ObjectEntry $reflection): bool
    {
        return '' !== self::functionNamespaceNameFromReflection($reflection);
    }

    /**
     * ReflectionMethod::getShortName() — method name (not Class::method); php-src (#22167).
     */
    public static function methodShortNameFromReflection(ObjectEntry $reflection): string
    {
        return self::methodNameFromReflection($reflection);
    }

    /**
     * ReflectionMethod::getNamespaceName() — empty for class methods (ns is on the class) (#22167).
     */
    public static function methodNamespaceNameFromReflection(ObjectEntry $reflection): string
    {
        return '';
    }

    /** ReflectionMethod::inNamespace() — always false for class methods (#22167). */
    public static function methodInNamespaceFromReflection(ObjectEntry $reflection): bool
    {
        return '' !== self::methodNamespaceNameFromReflection($reflection);
    }

    /**
     * ReflectionMethod::__toString() — php-src _function_string for methods (#22173, #22522).
     *
     * Covers user/internal header, visibility/static/abstract/final, by-ref name, file/line,
     * parameters (types / optional / defaults / variadic), and return type section.
     */
    public static function methodReflectionToString(Context $ctx, ObjectEntry $reflection): string
    {
        [$declaring, $methodLc] = self::resolveReflectedMethodDeclaring($ctx, $reflection);
        $flags = self::reflectedMethodCfgFlags($ctx, $reflection);
        $name = self::methodNameFromReflection($reflection);
        $isInternal = $declaring->isInternal;
        $tag = $isInternal ? '<internal' : '<user';
        if ($isInternal) {
            $ext = VmReflection::extensionNameForInternalClass($declaring->name);
            if ('' !== $ext) {
                $tag .= ':'.$ext;
            }
        }
        $tag .= '>';

        $mods = '';
        if (($flags & \PHPCfg\Func::FLAG_ABSTRACT) !== 0) {
            $mods .= 'abstract ';
        }
        if (($flags & \PHPCfg\Func::FLAG_FINAL) !== 0) {
            $mods .= 'final ';
        }
        if (($flags & \PHPCfg\Func::FLAG_STATIC) !== 0) {
            $mods .= 'static ';
        }
        if (($flags & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
            $mods .= 'private ';
        } elseif (($flags & \PHPCfg\Func::FLAG_PROTECTED) !== 0) {
            $mods .= 'protected ';
        } else {
            $mods .= 'public ';
        }

        $amp = self::methodReturnsReference($ctx, $reflection) ? '&' : '';
        $out = "Method [ {$tag} {$mods}method {$amp}{$name} ] {\n";

        $loc = self::methodSourceLocation($declaring, $methodLc);
        if (null !== $loc) {
            $loc = $loc->forReflection();
        }
        if (null !== $loc && '' !== $loc->filename && $loc->startLine > 0) {
            $end = $loc->endLine > 0 ? $loc->endLine : $loc->startLine;
            $out .= "  @@ {$loc->filename} {$loc->startLine} - {$end}\n";
        }

        $className = self::classNameFromReflection($reflection);
        $paramMetas = $declaring->methodParameterMetadata[$methodLc] ?? [];
        if ([] === $paramMetas) {
            $paramMetas = self::synthesizeParameterMetadataFromNames(
                self::reflectedMethodParameterNames($ctx, $className, $name),
                self::reflectedMethodRequiredParameterCount($ctx, $className, $name)
            );
        }
        $returnTypeStr = self::methodReturnTypeDumpString($declaring, $methodLc);
        $out .= self::formatFunctionParametersAndReturnSections($paramMetas, $returnTypeStr);

        $out .= "}\n";

        return $out;
    }

    /**
     * ReflectionClass::__toString() — php-src _class_string (#22379).
     *
     * Shape starts with `Class [ … ] {`; nested sections are a structured subset
     * (need not be byte-identical to Zend).
     */
    public static function classReflectionToString(Context $ctx, ObjectEntry $reflection): string
    {
        $className = self::classNameFromReflection($reflection);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            self::throwReflectionException(self::classNotFoundMessage($className));
        }

        $isInternal = $entry->isInternal;
        $tag = $isInternal ? '<internal' : '<user';
        if ($isInternal) {
            $ext = VmReflection::extensionNameForInternalClass($entry->name);
            if ('' !== $ext) {
                $tag .= ':'.$ext;
            }
        }
        $tag .= '>';

        $mods = '';
        if ($entry->isAbstract) {
            $mods .= 'abstract ';
        }
        if ($entry->isFinal) {
            $mods .= 'final ';
        }
        if ($entry->readonly) {
            $mods .= 'readonly ';
        }
        // Zend _class_string: enums export as "final class" with UnitEnum/BackedEnum (#22448).
        if ($entry->isInterface) {
            $kind = 'interface';
        } elseif ($entry->isTrait) {
            $kind = 'trait';
        } elseif ($entry->isEnum) {
            if (!$entry->isFinal && !str_contains($mods, 'final ')) {
                $mods .= 'final ';
            }
            $kind = 'class';
        } else {
            $kind = 'class';
        }

        $implements = '';
        $ifaceNames = VmReflection::reflectionClassInterfaceNamesList($entry, $ctx);
        if ([] !== $ifaceNames) {
            $implements = ' implements '.implode(', ', $ifaceNames);
        }

        $out = "Class [ {$tag} {$mods}{$kind} {$entry->name}{$implements} ] {\n";

        $loc = $entry->sourceLocation;
        if (null !== $loc) {
            $loc = $loc->forReflection();
        }
        if (null !== $loc && !$isInternal && '' !== $loc->filename && $loc->startLine > 0) {
            $end = $loc->endLine > 0 ? $loc->endLine : $loc->startLine;
            $out .= "  @@ {$loc->filename} {$loc->startLine} - {$end}\n";
        }

        $constants = [];
        foreach ($entry->constNames as $lc => $display) {
            $constants[] = $display;
        }
        $out .= "\n  - Constants [".\count($constants)."] {\n";
        foreach ($constants as $constName) {
            $out .= "    Constant [ {$constName} ]\n";
        }
        $out .= "  }\n";

        $staticProps = [];
        $instanceProps = [];
        foreach ($entry->staticProperties as $lc => $storage) {
            $staticProps[] = $storage->objectPropertyName ?? $lc;
        }
        foreach ($entry->properties as $prop) {
            if ($prop instanceof ClassProperty) {
                $instanceProps[] = $prop->name;
            }
        }
        $out .= "\n  - Static properties [".\count($staticProps)."] {\n";
        foreach ($staticProps as $propName) {
            $fake = self::makeTemporaryPropertyReflection($ctx, $entry->name, $propName);
            $out .= '    '.rtrim(self::propertyReflectionToString($ctx, $fake))."\n";
        }
        $out .= "  }\n";

        $staticMethods = [];
        $instanceMethods = [];
        foreach ($entry->methods as $methodLc => $_) {
            $vis = $entry->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
            $name = $entry->methodNames[$methodLc] ?? $methodLc;
            if (($vis & \PHPCfg\Func::FLAG_STATIC) !== 0) {
                $staticMethods[] = $name;
            } else {
                $instanceMethods[] = $name;
            }
        }
        foreach (array_keys($entry->abstractMethods) as $methodLc) {
            if (isset($entry->methods[$methodLc])) {
                continue;
            }
            $vis = $entry->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
            $name = $entry->methodNames[$methodLc] ?? $methodLc;
            if (($vis & \PHPCfg\Func::FLAG_STATIC) !== 0) {
                $staticMethods[] = $name;
            } else {
                $instanceMethods[] = $name;
            }
        }

        $out .= "\n  - Static methods [".\count($staticMethods)."] {\n";
        foreach ($staticMethods as $methodName) {
            $out .= self::formatNestedMethodLine($ctx, $entry->name, $methodName);
        }
        $out .= "  }\n";

        $out .= "\n  - Properties [".\count($instanceProps)."] {\n";
        foreach ($instanceProps as $propName) {
            $fake = self::makeTemporaryPropertyReflection($ctx, $entry->name, $propName);
            $out .= '    '.rtrim(self::propertyReflectionToString($ctx, $fake))."\n";
        }
        $out .= "  }\n";

        $out .= "\n  - Methods [".\count($instanceMethods)."] {\n";
        foreach ($instanceMethods as $methodName) {
            $out .= self::formatNestedMethodLine($ctx, $entry->name, $methodName);
        }
        $out .= "  }\n";

        $out .= "}\n";

        return $out;
    }

    /**
     * ReflectionProperty::__toString() — php-src _property_string (#22379).
     *
     * Must start with `Property [` and be non-empty.
     */
    public static function propertyReflectionToString(Context $ctx, ObjectEntry $reflection): string
    {
        $className = self::classNameFromReflection($reflection);
        $property = self::propertyNameFromReflection($reflection);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            self::throwReflectionException(self::classNotFoundMessage($className));
        }

        $isDynamic = self::isDynamicReflectionProperty($reflection);
        $modifiers = VmReflection::propertyReflectionModifiers($entry, $property, $ctx, $isDynamic);

        $mods = '';
        if (($modifiers & VmReflection::REFLECTION_IS_PUBLIC) !== 0) {
            $mods .= 'public ';
        } elseif (($modifiers & VmReflection::REFLECTION_IS_PROTECTED) !== 0) {
            $mods .= 'protected ';
        } elseif (($modifiers & VmReflection::REFLECTION_IS_PRIVATE) !== 0) {
            $mods .= 'private ';
        }
        if (($modifiers & VmReflection::REFLECTION_IS_STATIC) !== 0) {
            $mods .= 'static ';
        }
        if (($modifiers & VmReflection::REFLECTION_IS_READONLY) !== 0) {
            $mods .= 'readonly ';
        }
        if (($modifiers & VmReflection::REFLECTION_IS_FINAL) !== 0) {
            $mods .= 'final ';
        }

        $defaultPart = '';
        if (!$isDynamic) {
            $defaultPart = self::formatPropertyDefaultSuffix($ctx, $entry, $property);
        }

        return "Property [ {$mods}\${$property}{$defaultPart} ]\n";
    }

    /**
     * ReflectionFunction::__toString() — php-src _function_string for free functions (#22379, #22522).
     *
     * Must start with `Function [` and be non-empty.
     */
    public static function functionReflectionToString(Context $ctx, ObjectEntry $reflection): string
    {
        $name = self::functionNameFromReflection($reflection);
        $isInternal = self::isReflectionInternalFunction($reflection);
        $tag = $isInternal ? '<internal' : '<user';
        if ($isInternal) {
            $ext = VmReflection::extensionNameForFunction($ctx, $name);
            if ('' !== $ext) {
                $tag .= ':'.$ext;
            }
        }
        $tag .= '>';

        $amp = self::functionReturnsReference($ctx, $reflection) ? '&' : '';
        $out = "Function [ {$tag} function {$amp}{$name} ] {\n";

        if (!$isInternal) {
            $loc = self::functionSourceLocation($ctx, $reflection);
            if (null !== $loc) {
                $loc = $loc->forReflection();
            }
            if (null !== $loc && '' !== $loc->filename && $loc->startLine > 0) {
                $end = $loc->endLine > 0 ? $loc->endLine : $loc->startLine;
                $out .= "  @@ {$loc->filename} {$loc->startLine} - {$end}\n";
            }
        }

        $paramMetas = [];
        $returnTypeStr = null;
        if ($isInternal) {
            $paramMetas = self::synthesizeParameterMetadataFromNames(
                self::reflectedFunctionParameterNames($ctx, $reflection),
                self::reflectedFunctionRequiredParameterCount($ctx, $reflection)
            );
            $returnTypeStr = self::internalFunctionReturnTypeDumpString($ctx, $reflection);
        } else {
            $func = self::resolveFunctionFromReflection($ctx, $reflection);
            $paramMetas = $func->parameterMetadata;
            if ([] === $paramMetas) {
                $paramMetas = self::synthesizeParameterMetadataFromNames(
                    self::reflectedFunctionParameterNames($ctx, $reflection),
                    self::reflectedFunctionRequiredParameterCount($ctx, $reflection)
                );
            }
            $returnTypeStr = self::blockReturnTypeDumpString($func->block);
        }
        $out .= self::formatFunctionParametersAndReturnSections($paramMetas, $returnTypeStr);

        $out .= "}\n";

        return $out;
    }

    /** @return list<string> */
    private static function reflectedFunctionParameterNames(Context $ctx, ObjectEntry $reflection): array
    {
        return self::functionParameterNames($ctx, $reflection);
    }

    private static function reflectedFunctionRequiredParameterCount(Context $ctx, ObjectEntry $reflection): int
    {
        return self::functionNumberOfRequiredParameters($ctx, $reflection);
    }

    private static function formatPropertyDefaultSuffix(Context $ctx, ClassEntry $entry, string $property): string
    {
        $staticKey = VmReflection::findStaticPropertyKey($entry, $property, $ctx);
        if (null !== $staticKey) {
            $slot = $entry->staticProperties[$staticKey] ?? null;
            if ($slot instanceof Variable) {
                $printed = self::formatReflectionScalar($slot->resolveIndirect());
                if (null !== $printed) {
                    return ' = '.$printed;
                }
            }

            return '';
        }
        $meta = VmReflection::findClassProperty($entry, $property, $ctx);
        if (null === $meta || null === $meta->default) {
            return '';
        }
        $printed = self::formatReflectionScalar($meta->default->resolveIndirect());
        if (null === $printed) {
            return '';
        }

        return ' = '.$printed;
    }

    /**
     * @param list<ParameterMetadata> $paramMetas
     */
    private static function formatFunctionParametersAndReturnSections(array $paramMetas, ?string $returnTypeStr): string
    {
        $paramCount = \count($paramMetas);
        $showParams = $paramCount > 0 || null !== $returnTypeStr;
        if (!$showParams) {
            return '';
        }

        $out = "\n  - Parameters [{$paramCount}] {\n";
        foreach ($paramMetas as $i => $meta) {
            $out .= self::formatParameterDumpLine($i, $meta);
        }
        $out .= "  }\n";
        if (null !== $returnTypeStr) {
            $out .= "  - Return [ {$returnTypeStr} ]\n";
        }

        return $out;
    }

    private static function formatParameterDumpLine(int $index, ParameterMetadata $meta): string
    {
        $kind = ($meta->isOptional || $meta->isVariadic) ? 'optional' : 'required';
        $typePrefix = (null !== $meta->typeString && '' !== $meta->typeString)
            ? $meta->typeString.' '
            : '';
        $amp = $meta->byRef ? '&' : '';
        $dots = $meta->isVariadic ? '...' : '';
        $default = '';
        if (!$meta->isVariadic && null !== $meta->defaultExport && '' !== $meta->defaultExport) {
            $default = ' = '.$meta->defaultExport;
        }

        return "    Parameter #{$index} [ <{$kind}> {$typePrefix}{$amp}{$dots}\${$meta->name}{$default} ]\n";
    }

    /**
     * @param list<string> $names
     *
     * @return list<ParameterMetadata>
     */
    private static function synthesizeParameterMetadataFromNames(array $names, int $required): array
    {
        $out = [];
        foreach ($names as $i => $name) {
            $out[] = new ParameterMetadata(
                $name,
                [],
                false,
                $i >= $required,
                false,
                false,
                null,
                null,
            );
        }

        return $out;
    }

    private static function methodReturnTypeDumpString(ClassEntry $declaring, string $methodLc): ?string
    {
        $func = $declaring->methods[$methodLc] ?? null;
        if (!$func instanceof PhpFunc) {
            return null;
        }

        return self::blockReturnTypeDumpString($func->block);
    }

    private static function blockReturnTypeDumpString(Block $block): ?string
    {
        $declared = $block->returnDeclaredType;
        if (!self::hasDeclaredReturnType($declared)) {
            return null;
        }

        return ReflectionTypeSupport::cfgTypeStringForDump($declared);
    }

    private static function internalFunctionReturnTypeDumpString(Context $ctx, ObjectEntry $reflection): ?string
    {
        $declared = self::reflectedFunctionInternalReturnType($reflection);
        if (!self::hasDeclaredReturnType($declared)) {
            return null;
        }

        return ReflectionTypeSupport::cfgTypeStringForDump($declared);
    }

    private static function formatReflectionScalar(Variable $value): ?string
    {
        switch ($value->type) {
            case Variable::TYPE_NULL:
                return 'NULL';
            case Variable::TYPE_BOOLEAN:
                return $value->toBool() ? 'true' : 'false';
            case Variable::TYPE_INTEGER:
                return (string) $value->toInt();
            case Variable::TYPE_FLOAT:
                return (string) $value->toFloat();
            case Variable::TYPE_STRING:
                return var_export($value->toString(), true);
            default:
                return null;
        }
    }

    private static function makeTemporaryPropertyReflection(
        Context $ctx,
        string $className,
        string $property
    ): ObjectEntry {
        $propClass = $ctx->classes[self::REFLECTION_PROPERTY] ?? null;
        if (null === $propClass) {
            throw new \LogicException('ReflectionProperty is not registered in this compiler build');
        }
        $obj = new ObjectEntry($propClass);
        $obj->constructed = true;
        // Zend public surface: $name = property, $class = declaring class (#22504).
        $obj->getProperty(self::PROP_PROPERTY_NAME)->string($property);
        $obj->getProperty(self::PROP_DECLARING_CLASS_NAME)->string($className);
        $obj->getProperty(self::PROP_IS_DYNAMIC)->bool(false);
        $obj->getProperty(self::PROP_ACCESSIBLE)->bool(false);

        return $obj;
    }

    private static function formatNestedMethodLine(Context $ctx, string $className, string $methodName): string
    {
        $methodClass = $ctx->classes[self::REFLECTION_METHOD] ?? null;
        if (null === $methodClass) {
            return "    Method [ method {$methodName} ] {\n    }\n";
        }
        $obj = new ObjectEntry($methodClass);
        $obj->constructed = true;
        $obj->getProperty(self::PROP_REFLECTION_METHOD_CLASS)->string($className);
        $obj->getProperty(self::PROP_REFLECTION_METHOD_FUNC)->string($methodName);
        $obj->getProperty(self::PROP_ACCESSIBLE)->bool(false);
        $body = self::methodReflectionToString($ctx, $obj);
        $lines = explode("\n", rtrim($body));
        $indented = '';
        foreach ($lines as $line) {
            $indented .= '    '.$line."\n";
        }

        return $indented;
    }

    /**
     * ReflectionFunction::{getFileName,getStartLine,getEndLine} — false for internals / missing (#22144).
     */
    public static function returnFunctionFileName(?Variable $returnVar, ?SourceLocation $location, bool $isInternal): void
    {
        if (null === $returnVar) {
            return;
        }
        if ($isInternal || null === $location) {
            $returnVar->bool(false);

            return;
        }
        $location = $location->forReflection();
        $file = $location->filename;
        if ('' === $file || 'unknown' === $file) {
            $returnVar->bool(false);

            return;
        }
        $returnVar->string($file);
    }

    public static function returnFunctionStartLine(?Variable $returnVar, ?SourceLocation $location, bool $isInternal): void
    {
        if (null === $returnVar) {
            return;
        }
        if (null !== $location) {
            $location = $location->forReflection();
        }
        if ($isInternal || null === $location || $location->startLine <= 0) {
            $returnVar->bool(false);

            return;
        }
        $returnVar->int($location->startLine);
    }

    public static function returnFunctionEndLine(?Variable $returnVar, ?SourceLocation $location, bool $isInternal): void
    {
        if (null === $returnVar) {
            return;
        }
        if (null !== $location) {
            $location = $location->forReflection();
        }
        if ($isInternal || null === $location || $location->endLine <= 0) {
            $returnVar->bool(false);

            return;
        }
        $returnVar->int($location->endLine);
    }

    public static function propertySourceLocation(Context $ctx, ClassEntry $entry, string $property): ?SourceLocation
    {
        $lc = strtolower($property);
        $current = $entry;
        while (true) {
            if (isset($current->propertySourceLocations[$lc])) {
                return $current->propertySourceLocations[$lc];
            }
            if (null === $current->parentLc || !isset($ctx->classes[$current->parentLc])) {
                return null;
            }
            $current = $ctx->classes[$current->parentLc];
        }
    }

    /**
     * Class-constant declaration site (docblock + lines) (#22419).
     */
    public static function classConstantSourceLocation(Context $ctx, ObjectEntry $reflection): ?SourceLocation
    {
        $className = self::classNameFromReflection($reflection);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            self::throwReflectionException(self::classNotFoundMessage($className));
        }
        $constant = self::constantNameFromReflection($reflection);
        $decl = VmReflection::findClassConstantDecl($entry, $constant, $ctx);
        if (null === $decl) {
            self::throwReflectionException(self::constantNotFoundMessage($className, $constant));
        }

        return $decl['declaring']->constSourceLocations[$decl['constLc']] ?? null;
    }

    /**
     * ReflectionClassConstant::__toString() — php-src _class_const_string (#22419).
     *
     * Shape: `Constant [ {final }{visibility} {type} {name} ] { {value} }\n`
     */
    public static function classConstantReflectionToString(Context $ctx, ObjectEntry $reflection): string
    {
        $className = self::classNameFromReflection($reflection);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            self::throwReflectionException(self::classNotFoundMessage($className));
        }
        $constant = self::constantNameFromReflection($reflection);
        $decl = VmReflection::findClassConstantDecl($entry, $constant, $ctx);
        if (null === $decl) {
            self::throwReflectionException(self::constantNotFoundMessage($className, $constant));
        }
        $declaring = $decl['declaring'];
        $key = $decl['constLc'];
        $canonical = $declaring->constNames[$key] ?? $constant;
        $visFlags = MethodVisibility::mask(
            $declaring->constVisibility[$key] ?? \PHPCfg\Func::FLAG_PUBLIC
        );
        if (($visFlags & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
            $visibility = 'private';
        } elseif (($visFlags & \PHPCfg\Func::FLAG_PROTECTED) !== 0) {
            $visibility = 'protected';
        } else {
            $visibility = 'public';
        }
        $final = isset($declaring->constFinal[$key]) ? 'final ' : '';
        $value = $declaring->constants[$key]->resolveIndirect();
        $declared = $declaring->constDeclaredTypes[$key] ?? null;
        $type = null !== $declared
            ? ReflectionTypeSupport::cfgTypeString($declared)
            : EnumCaseSupport::typeNameForVariable($value);
        if (Variable::TYPE_ARRAY === $value->type) {
            $printed = 'Array';
        } elseif (Variable::TYPE_OBJECT === $value->type) {
            $printed = 'Object';
        } else {
            $printed = $value->toString();
        }

        return sprintf(
            "Constant [ %s%s %s %s ] { %s }\n",
            $final,
            $visibility,
            $type,
            $canonical,
            $printed
        );
    }

    public static function returnDocComment(?Variable $returnVar, ?string $docComment): void
    {
        if (null === $returnVar) {
            return;
        }
        if (null === $docComment || '' === $docComment) {
            $returnVar->bool(false);

            return;
        }
        $returnVar->string($docComment);
    }

    public static function returnFileName(?Variable $returnVar, ClassEntry $entry, SourceLocation $location): void
    {
        if (null === $returnVar) {
            return;
        }
        if ($entry->isInternal) {
            $returnVar->bool(false);

            return;
        }
        $file = $location->filename;
        if ('' === $file || 'unknown' === $file) {
            $returnVar->bool(false);

            return;
        }
        $returnVar->string($file);
    }

    public static function returnExtensionName(?Variable $returnVar, ClassEntry $entry): void
    {
        if (null === $returnVar) {
            return;
        }
        if (!$entry->isInternal) {
            $returnVar->bool(false);

            return;
        }
        $returnVar->string(VmReflection::extensionNameForInternalClass($entry->name));
    }

    public static function returnExtension(?Variable $returnVar, ClassEntry $entry, Context $ctx): void
    {
        if (null === $returnVar) {
            return;
        }
        if (!$entry->isInternal) {
            $returnVar->null();

            return;
        }
        $returnVar->object(self::newReflectionExtensionObject(
            $ctx,
            VmReflection::extensionNameForInternalClass($entry->name)
        ));
    }

    public static function newReflectionExtensionObject(Context $ctx, string $name): ObjectEntry
    {
        $class = $ctx->classes[self::REFLECTION_EXTENSION] ?? null;
        if (null === $class) {
            throw new \LogicException('ReflectionExtension is not registered in this compiler build');
        }
        $obj = new ObjectEntry($class);
        $obj->getProperty(self::PROP_EXTENSION_NAME)->string($name);
        $obj->constructed = true;

        return $obj;
    }

    public static function newReflectionFunctionObject(Context $ctx): ObjectEntry
    {
        $rfClass = $ctx->classes[self::REFLECTION_FUNCTION] ?? null;
        if (null === $rfClass) {
            throw new \LogicException('ReflectionFunction is not registered in this compiler build');
        }
        $rf = new ObjectEntry($rfClass);
        $rf->constructed = true;

        return $rf;
    }

    /**
     * ReflectionFunction::createFromFunction() — php-src ext/reflection/php_reflection.c (#6994).
     */
    public static function reflectionFunctionFromFunctionName(Context $ctx, string $functionName): ObjectEntry
    {
        if (str_contains($functionName, '::')) {
            self::throwReflectionException(self::functionNotFoundMessage($functionName));
        }
        $func = self::resolveFunctionForReflection($ctx, $functionName);
        $rf = self::newReflectionFunctionObject($ctx);
        $rf->reflectionIsInternalFunction = $func instanceof Func\Internal;
        $rf->getProperty(self::PROP_REFLECTION_FUNCTION_NAME)->string($functionName);

        return $rf;
    }

    /**
     * ReflectionFunction::createFromCallable() — php-src ext/reflection/php_reflection.c (#7039).
     */
    public static function reflectionFunctionFromCallable(Context $ctx, Frame $frame, Variable $callable): ObjectEntry
    {
        $callable = $callable->resolveIndirect();
        if (VmClosureCall::isClosure($callable)) {
            return self::populateReflectionFunctionFromClosureState(
                $ctx,
                VmClosureCall::resolve($callable)
            );
        }
        if (!\in_array($callable->type, [Variable::TYPE_OBJECT, Variable::TYPE_STRING, Variable::TYPE_ARRAY], true)) {
            self::throwReflectionException(
                'ReflectionFunction::createFromCallable(): Argument #1 ($function) must be a valid callback'
            );
        }
        try {
            if (Variable::TYPE_STRING === $callable->type && !str_contains($callable->toString(), '::')) {
                return self::reflectionFunctionFromFunctionName($ctx, $callable->toString());
            }
            $closureObj = ClosureSupport::fromCallable($ctx, $frame, $callable);
            $state = ClosureSupport::requireClosureState(
                $closureObj,
                'ReflectionFunction::createFromCallable()'
            );

            return self::populateReflectionFunctionFromClosureState($ctx, $state);
        } catch (\LogicException|\Error) {
            self::throwReflectionException(
                'ReflectionFunction::createFromCallable(): Argument #1 ($function) must be a valid callback'
            );
        }
    }

    /**
     * @param array<int, Variable> $invokeArgs possibly sparse (named optionals, #23388)
     */
    public static function invokeReflectionFunction(
        VmEngine $vm,
        Frame $frame,
        ObjectEntry $reflection,
        array $invokeArgs
    ): Variable {
        $ctx = VmReflection::requireContext($frame);
        $closure = $reflection->reflectionClosureState;
        if (null !== $closure) {
            return $vm->invokeClosureWithCalledArgs($closure, $invokeArgs);
        }
        $name = self::functionNameFromReflection($reflection);
        $func = $ctx->functions[strtolower($name)] ?? null;
        if (null === $func) {
            self::throwReflectionException(self::functionNotFoundMessage($name));
        }
        if ($func instanceof Func\Internal) {
            $child = $func->getFrame($ctx, $frame);
            $child->vmContext = $ctx;
            $child->calledArgs = $invokeArgs;
            $out = new Variable();
            $child->returnVar = $out;
            $func->execute($child);

            return $out->resolveIndirect();
        }
        if (!$func instanceof Func\PHP) {
            throw new \LogicException('ReflectionFunction::invoke() target is not invokable in this compiler build');
        }

        return $vm->invokePhpFunctionWithCalledArgs($func, $invokeArgs);
    }

    /**
     * ReflectionMethod::createFromClosure() — php-src ext/reflection/php_reflection.c (#7039).
     */
    public static function reflectionMethodFromClosure(
        Context $ctx,
        Variable $closureArg,
        ?Variable $scopeArg,
        ?Variable $nameArg
    ): ObjectEntry {
        $closureArg = $closureArg->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $closureArg->type
            || 'closure' !== strtolower($closureArg->toObject()->class->name)) {
            throw new \TypeError(
                'ReflectionMethod::createFromClosure(): Argument #1 ($closure) must be of type Closure, '
                .self::valueTypeLabel($closureArg).' given'
            );
        }
        $state = ClosureSupport::requireClosureState(
            $closureArg->toObject(),
            'ReflectionMethod::createFromClosure()'
        );
        if ($state->isUserClosure()) {
            self::throwReflectionException('Given closure was not created from a method');
        }
        [$className, $methodName] = self::methodScopeAndNameFromClosureState($ctx, $state);
        if (null !== $scopeArg) {
            $scopeArg = $scopeArg->resolveIndirect();
            if (Variable::TYPE_NULL !== $scopeArg->type) {
                $className = VmReflection::stringArg($scopeArg, 'ReflectionMethod::createFromClosure() scope', 2);
            }
        }
        if (null !== $nameArg) {
            $nameArg = $nameArg->resolveIndirect();
            if (Variable::TYPE_NULL !== $nameArg->type) {
                $methodName = VmReflection::stringArg($nameArg, 'ReflectionMethod::createFromClosure() name', 3);
            }
        }
        if (null === $className || null === $methodName || '' === $className || '' === $methodName) {
            self::throwReflectionException('Given closure was not created from a method');
        }
        [$entry, $methodName] = self::reflectionMethodFromClassAndMethod($ctx, $className, $methodName);

        return self::newReflectionMethodObject($ctx, $entry, $methodName);
    }

    private static function populateReflectionFunctionFromClosureState(Context $ctx, ClosureState $state): ObjectEntry
    {
        $rf = self::newReflectionFunctionObject($ctx);
        $rf->reflectionClosureState = $state;
        $rf->getProperty(self::PROP_REFLECTION_FUNCTION_NAME)->string(self::displayNameForClosureState($state));

        return $rf;
    }

    /**
     * ReflectionFunction::getName() for Closure objects (zend_closures.c / php_reflection.c).
     *
     * fromCallable wrappers keep the underlying function or method short name (#22330);
     * plain user closures stay `{closure}` / `{anonymous}#N`.
     */
    public static function displayNameForClosureState(ClosureState $state): string
    {
        if (null !== $state->methodName && '' !== $state->methodName) {
            // Zend reports the method name only (e.g. createFromFormat / m), not Class::method.
            return $state->methodName;
        }
        if (null !== $state->wrappedFunc) {
            return $state->wrappedFunc->getName();
        }
        $name = $state->func->getName();
        if (preg_match('/^\{anonymous\}#\d+$/', $name)) {
            return '{closure}';
        }

        return $name;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    public static function isReflectionClosure(ObjectEntry $reflection): bool
    {
        return null !== $reflection->reflectionClosureState;
    }

    /**
     * php-src: ReflectionFunctionAbstract::isStatic() for ReflectionFunction (#22024).
     * Named/internal functions are never static; closures report ZEND_ACC_STATIC / FLAG_STATIC.
     */
    public static function isReflectionFunctionStatic(ObjectEntry $reflection): bool
    {
        $state = $reflection->reflectionClosureState;
        if (null === $state) {
            return false;
        }

        return $state->isStaticClosure();
    }

    public static function newReflectionClassObjectForName(Context $ctx, string $className): ObjectEntry
    {
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            self::throwReflectionException(self::classNotFoundMessage($className));
        }
        $rcClass = $ctx->classes[self::REFLECTION_CLASS] ?? null;
        if (null === $rcClass) {
            throw new \LogicException('ReflectionClass is not registered in this compiler build');
        }
        $rc = new ObjectEntry($rcClass);
        $rc->constructed = true;
        $rc->getProperty(self::PROP_CLASS_NAME)->string($entry->name);

        return $rc;
    }

    /**
     * Build a constructed ReflectionEnum for an enum class name (#19785, ext/reflection/php_reflection.c).
     */
    public static function newReflectionEnumObjectForName(Context $ctx, string $enumClassName): ObjectEntry
    {
        $entry = VmReflection::resolveClassEntry($ctx, $enumClassName);
        if (null === $entry || !$entry->isEnum) {
            self::throwReflectionException(self::classNotEnumMessage($enumClassName));
        }
        $reClass = $ctx->classes[self::REFLECTION_ENUM] ?? null;
        if (null === $reClass) {
            throw new \LogicException('ReflectionEnum is not registered in this compiler build');
        }
        $re = new ObjectEntry($reClass);
        $re->constructed = true;
        $re->getProperty(self::PROP_CLASS_NAME)->string($entry->name);

        return $re;
    }

    /**
     * ReflectionConstant::getNamespaceName() — php-src zend_memrchr on constant name (#21551).
     */
    public static function globalConstantNamespaceName(string $name): string
    {
        $pos = strrpos($name, '\\');
        if (false === $pos) {
            return '';
        }

        return substr($name, 0, $pos);
    }

    /**
     * ReflectionConstant::getShortName() — php-src suffix after last backslash (#21551).
     */
    public static function globalConstantShortName(string $name): string
    {
        $pos = strrpos($name, '\\');
        if (false === $pos) {
            return $name;
        }

        return substr($name, $pos + 1);
    }

    /**
     * ReflectionConstant::__toString() — php-src _const_string (#21551).
     */
    public static function globalReflectionConstantToString(Context $ctx, ObjectEntry $reflection): string
    {
        $name = self::constantNameFromReflection($reflection);
        $value = \PHPCompiler\ext\standard\VmConstants::constantLookup($ctx, $name);
        if (null === $value) {
            self::throwReflectionException(self::globalConstantNotFoundMessage($name));
        }
        $value = $value->resolveIndirect();
        $type = EnumCaseSupport::typeNameForVariable($value);
        $flags = [];
        if (!$ctx->isUserConstantDefined($name)) {
            $flags[] = 'persistent';
        }
        $meta = $ctx->globalConstDeprecated[strtolower($name)] ?? null;
        if (null !== $meta && $meta->isDeprecatedForReflection()) {
            $flags[] = 'deprecated';
        }
        $flagPart = [] !== $flags ? '<' . implode(', ', $flags) . '> ' : '';
        if (Variable::TYPE_ARRAY === $value->type) {
            $printed = 'Array';
        } else {
            $printed = $value->toString();
        }

        return sprintf("Constant [ %s%s %s ] { %s }\n", $flagPart, $type, $name, $printed);
    }

    /**
     * ReflectionConstant::getFileName() — user filename or null for internals (#21551).
     */
    public static function globalReflectionConstantFileName(Context $ctx, ObjectEntry $reflection): ?string
    {
        $name = self::constantNameFromReflection($reflection);
        if (!$ctx->isUserConstantDefined($name)) {
            return null;
        }

        return $ctx->globalConstantFilenames[$name] ?? null;
    }

    /**
     * ReflectionConstant::getExtensionName() — null means user constant (return false/null) (#21551).
     */
    public static function globalReflectionConstantExtensionName(Context $ctx, ObjectEntry $reflection): ?string
    {
        $name = self::constantNameFromReflection($reflection);
        if ($ctx->isUserConstantDefined($name)) {
            return null;
        }
        $ext = \PHPCompiler\ext\standard\ExtensionConstantGroups::extensionNameForConstant($name);
        if (null !== $ext) {
            return $ext;
        }

        return 'Core';
    }

    /**
     * ReflectionEnumUnitCase::__toString() — php-src reflection_class_constant_to_string (#19785).
     */
    public static function enumCaseReflectionToString(ObjectEntry $reflection): string
    {
        $enumName = self::enumClassNameFromReflection($reflection);
        $caseName = self::enumCaseNameFromReflection($reflection);

        return sprintf("Constant [ public %s %s ] { Object }\n", $enumName, $caseName);
    }

    /**
     * ReflectionAttribute::__toString() — php-src ZEND_METHOD(ReflectionAttribute, __toString) (#22420).
     */
    public static function attributeReflectionToString(ObjectEntry $reflection): string
    {
        $nameVar = $reflection->getProperty(self::PROP_ATTR_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionAttribute missing name');
        }

        return sprintf("Attribute [ %s ]\n", $nameVar->toString());
    }

    /** php-src: closure_func->common.scope (definition / bind scope). */
    public static function closureDefinitionScopeClassName(ClosureState $state): ?string
    {
        if (null !== $state->methodName && '' !== $state->methodName) {
            if (null !== $state->boundScopeClass && '' !== $state->boundScopeClass) {
                return $state->boundScopeClass;
            }
            $wrapped = $state->wrappedFunc;
            if ($wrapped instanceof Func\PHP) {
                $cfgFunc = $wrapped->block->func ?? null;
                if (null !== $cfgFunc && null !== $cfgFunc->class && null !== $cfgFunc->class->value && '' !== $cfgFunc->class->value) {
                    return $cfgFunc->class->value;
                }
            }
            if (null !== $state->methodReceiver) {
                $recv = $state->methodReceiver->resolveIndirect();
                if (Variable::TYPE_OBJECT === $recv->type) {
                    return $recv->toObject()->class->name;
                }
            }

            return null;
        }
        if (null !== $state->wrappedFunc) {
            $wrapped = $state->wrappedFunc;
            if ($wrapped instanceof Func\PHP) {
                $cfgFunc = $wrapped->block->func ?? null;
                if (null !== $cfgFunc && null !== $cfgFunc->class && null !== $cfgFunc->class->value && '' !== $cfgFunc->class->value) {
                    return $cfgFunc->class->value;
                }
            }

            return null;
        }
        // User closure: bindTo updates boundScopeClass (ce); prefer it over CFG func->class (#25793).
        if (null !== $state->boundScopeClass && '' !== $state->boundScopeClass) {
            return $state->boundScopeClass;
        }
        $cfgFunc = $state->func->block->func ?? null;
        if (null === $cfgFunc || null === $cfgFunc->class || null === $cfgFunc->class->value || '' === $cfgFunc->class->value) {
            return null;
        }

        return $cfgFunc->class->value;
    }

    /** php-src: get_closure called_scope, else definition scope. */
    public static function closureCalledScopeClassName(ClosureState $state): ?string
    {
        if (null !== $state->boundThis) {
            $thisObj = $state->boundThis->resolveIndirect();
            if (Variable::TYPE_OBJECT === $thisObj->type) {
                return $thisObj->toObject()->class->name;
            }
        }
        if (null !== $state->boundCalledScopeClass && '' !== $state->boundCalledScopeClass) {
            return $state->boundCalledScopeClass;
        }
        if (null !== $state->boundScopeClass && '' !== $state->boundScopeClass) {
            return $state->boundScopeClass;
        }

        return self::closureDefinitionScopeClassName($state);
    }

    /** php-src: ReflectionFunctionAbstract::getClosureUsedVariables(). */
    public static function returnClosureUsedVariables(?Variable $returnVar, ClosureState $state): void
    {
        if (null === $returnVar) {
            return;
        }
        $returnVar->newArray();
        self::addClosureUsedVariablesToHashTable($returnVar->toArray(), $state);
    }

    /**
     * Bound `use` captures for a live ClosureState (php-src closure static table).
     * Shared by getClosureUsedVariables() and getStaticVariables() (#25558).
     */
    private static function addClosureUsedVariablesToHashTable(HashTable $ht, ClosureState $state): void
    {
        $block = $state->func->block;
        /** @var array<int, Variable> $bySlot */
        $bySlot = [];
        foreach ($state->captures as $capture) {
            $bySlot[$capture['slot']] = $capture['var'];
        }
        foreach ($block->eachNamedScopeSlot() as [$name, $slot]) {
            if (!isset($block->closureCaptureSlots[$slot]) || !isset($bySlot[$slot])) {
                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($bySlot[$slot]->resolveIndirect());
            $ht->add($name, $copy);
        }
    }

    private static function methodScopeAndNameFromClosureState(Context $ctx, ClosureState $state): array
    {
        if (null !== $state->methodName && '' !== $state->methodName) {
            $className = $state->boundScopeClass;
            if (null === $className || '' === $className) {
                if (null !== $state->methodReceiver) {
                    $recv = $state->methodReceiver->resolveIndirect();
                    if (Variable::TYPE_OBJECT === $recv->type) {
                        $className = $recv->toObject()->class->name;
                    }
                }
            }

            return [$className, $state->methodName];
        }
        $wrapped = $state->wrappedFunc;
        if ($wrapped instanceof Func\PHP) {
            $cfgFunc = $wrapped->block->func ?? null;
            if (null !== $cfgFunc && null !== $cfgFunc->class) {
                $className = $cfgFunc->class->value;
                $methodLc = strtolower($wrapped->getName());
                if (str_contains($methodLc, '::')) {
                    $methodLc = strtolower(substr($methodLc, strrpos($methodLc, '::') + 2));
                }
                $entry = VmReflection::resolveClassEntry($ctx, $className);
                if (null !== $entry && (isset($entry->methods[$methodLc]) || isset($entry->abstractMethods[$methodLc]))) {
                    return [$entry->name, $entry->methodNames[$methodLc] ?? $methodLc];
                }
                $shortName = $wrapped->getName();
                if (str_contains($shortName, '::')) {
                    $shortName = substr($shortName, strrpos($shortName, '::') + 2);
                }

                return [$className, $shortName];
            }
        }
        // Static/internal method wrappers keep boundScopeClass + wrapped Func name (#25559).
        if (null !== $wrapped && null !== $state->boundScopeClass && '' !== $state->boundScopeClass) {
            $shortName = $wrapped->getName();
            if (str_contains($shortName, '::')) {
                $shortName = substr($shortName, strrpos($shortName, '::') + 2);
            }

            return [$state->boundScopeClass, $shortName];
        }

        return [null, null];
    }

    /** php-src: ReflectionFunctionAbstract::getStaticVariables() (#14166). */
    public static function returnStaticVariablesFromFunctionReflection(
        Context $ctx,
        ObjectEntry $reflection,
        ?Variable $returnVar
    ): void {
        if (null === $returnVar) {
            return;
        }
        if ($reflection->reflectionIsInternalFunction) {
            $returnVar->newArray();

            return;
        }
        $closure = $reflection->reflectionClosureState;
        if (null !== $closure) {
            self::returnFunctionStaticVariables($ctx, $returnVar, $closure->func, $closure);

            return;
        }
        try {
            $func = self::resolveFunctionFromReflection($ctx, $reflection);
        } catch (\ReflectionException) {
            $returnVar->newArray();

            return;
        }
        self::returnFunctionStaticVariables($ctx, $returnVar, $func, null);
    }

    /** php-src: ReflectionMethod::getStaticVariables() (#14166). */
    public static function returnStaticVariablesFromMethodReflection(
        Context $ctx,
        ObjectEntry $reflection,
        ?Variable $returnVar
    ): void {
        if (null === $returnVar) {
            return;
        }
        $func = self::resolvePhpFuncFromReflectionMethod($ctx, $reflection);
        if (null === $func) {
            $returnVar->newArray();

            return;
        }
        self::returnFunctionStaticVariables($ctx, $returnVar, $func, null);
    }

    public static function returnFunctionStaticVariables(
        Context $ctx,
        ?Variable $returnVar,
        PhpFunc $func,
        ?ClosureState $closureState
    ): void {
        if (null === $returnVar) {
            return;
        }
        $returnVar->newArray();
        $ht = $returnVar->toArray();
        // php-src: closure getStaticVariables() = use captures then function-static locals (#25558).
        if (null !== $closureState) {
            self::addClosureUsedVariablesToHashTable($ht, $closureState);
        }
        foreach (self::collectFunctionStaticVarDeclarations($func->block) as $varName => $info) {
            $copy = self::resolveStaticVarReflectionValue(
                $ctx,
                $info['storageKey'],
                $info['defaultSlot'],
                $info['block'],
                $closureState
            );
            $ht->add($varName, $copy);
        }
    }

    /**
     * @return array<string, array{storageKey: string, defaultSlot: ?int, block: Block}>
     */
    private static function collectFunctionStaticVarDeclarations(Block $entry): array
    {
        $decls = [];
        foreach (self::collectBlocksForStaticReflection($entry) as $block) {
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_DECLARE_FUNCTION_STATIC !== $op->type) {
                    continue;
                }
                $varName = $op->functionStaticVarName;
                if (null === $varName || '' === $varName || !isset($block->constants[$op->arg2])) {
                    continue;
                }
                if (!isset($decls[$varName])) {
                    $decls[$varName] = [
                        'storageKey' => $block->constants[$op->arg2]->toString(),
                        'defaultSlot' => null !== $op->arg3 ? (int) $op->arg3 : null,
                        'block' => $block,
                    ];
                }
            }
        }

        return $decls;
    }

    /** @return list<Block> */
    private static function collectBlocksForStaticReflection(Block $block): array
    {
        $seen = new \SplObjectStorage();
        $out = [];
        self::collectBlocksForStaticReflectionInternal($block, $seen, $out);

        return $out;
    }

    /** @param list<Block> $out */
    private static function collectBlocksForStaticReflectionInternal(
        Block $block,
        \SplObjectStorage $seen,
        array &$out
    ): void {
        if ($seen->contains($block)) {
            return;
        }
        $seen->attach($block);
        $out[] = $block;
        foreach ($block->blocks as $nested) {
            self::collectBlocksForStaticReflectionInternal($nested, $seen, $out);
        }
        foreach ($block->opCodes as $op) {
            foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                if ($sub instanceof Block) {
                    self::collectBlocksForStaticReflectionInternal($sub, $seen, $out);
                }
            }
        }
    }

    private static function resolveStaticVarReflectionValue(
        Context $ctx,
        string $storageKey,
        ?int $defaultSlot,
        Block $block,
        ?ClosureState $closureState
    ): Variable {
        $copy = new Variable();
        $initialized = false;
        $source = null;
        if (null !== $closureState && !str_contains($storageKey, "\0")) {
            $source = $closureState->peekStatic($storageKey);
            $initialized = $closureState->isStaticInitialized($storageKey);
        } else {
            $source = $ctx->peekFunctionStatic($storageKey);
            $initialized = $ctx->isFunctionStaticInitialized($storageKey);
        }
        if ($initialized && null !== $source) {
            $copy->copyFrom($source->resolveIndirect());

            return $copy;
        }
        if (null !== $defaultSlot && isset($block->constants[$defaultSlot])) {
            $copy->copyFrom($block->constants[$defaultSlot]);

            return $copy;
        }
        $copy->null();

        return $copy;
    }

    private static function resolvePhpFuncFromReflectionMethod(Context $ctx, ObjectEntry $reflection): ?PhpFunc
    {
        $className = self::classNameFromReflection($reflection);
        $method = self::methodNameFromReflection($reflection);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            return null;
        }
        $func = $entry->methods[strtolower($method)] ?? null;
        if (!$func instanceof PhpFunc) {
            return null;
        }

        return $func;
    }
}
