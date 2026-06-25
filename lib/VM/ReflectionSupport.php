<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Compiler\AttributeEntry;
use PHPCompiler\Compiler\AttributeNames;
use PHPCompiler\Compiler\CompileTimeEnumCase;
use PHPCompiler\Compiler\CompileTimeNew;
use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\ext\standard\VmClosureCall;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\Func;
use PHPCfg\Op\Type as CfgType;
use PHPCompiler\VM as VmEngine;
use PHPCompiler\VM\Builtin\AttributeConstruct;
use PHPCompiler\VM\Builtin\DeprecatedConstruct;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * Build ReflectionAttribute stubs from compile-time metadata (#1936, #3206, #3340, #3800).
 */
final class ReflectionSupport
{
    public const REFLECTION_CLASS = 'reflectionclass';

    public const REFLECTION_METHOD = 'reflectionmethod';

    public const REFLECTION_PROPERTY = 'reflectionproperty';

    public const REFLECTION_FUNCTION = 'reflectionfunction';

    public const REFLECTION_CONSTANT = 'reflectionconstant';

    public const REFLECTION_CLASS_CONSTANT = 'reflectionclassconstant';

    public const REFLECTION_ATTRIBUTE = 'reflectionattribute';

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

    public const PROP_CLASS_NAME = 'name';

    public const PROP_METHOD_NAME = 'method';

    public const PROP_PROPERTY_NAME = 'property';

    /** Declaring class name on ReflectionProperty instances (#9878). */
    public const PROP_DECLARING_CLASS_NAME = 'declaringClass';

    public const PROP_FUNCTION_NAME = 'function';

    public const PROP_CONSTANT_NAME = 'constant';

    public const PROP_ATTR_NAME = 'name';

    /** Serialized attribute ctor args on ReflectionAttribute instances (#3206). */
    public const PROP_ATTR_ARGS = 'args';

    /** Whether this attribute name is duplicated on the target (#6912). */
    public const PROP_ATTR_IS_REPEATED = 'isRepeated';

    public const PROP_ENUM_CASE_NAME = 'case';

    public const PROP_FUNC_NAME = 'funcName';

    /** Wrapped Fiber object on ReflectionFiber instances (#6793). */
    public const PROP_FIBER_TARGET = 'fiber';

    public const PROP_PARAM_INDEX = 'paramIndex';

    public const PROP_PARAM_NAME = 'paramName';

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
     * @param list<string> $names
     */
    public static function attributesArray(Frame $frame, array $names): Variable
    {
        $entries = [];
        foreach ($names as $name) {
            $entries[] = new AttributeEntry($name);
        }

        return self::attributesArrayFromEntries($frame, $entries);
    }

    /**
     * @param list<AttributeEntry> $entries
     */
    public static function attributesArrayFromEntries(Frame $frame, array $entries): Variable
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

        return self::scalarToVariable($value);
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
            throw new \Error(
                'Cannot access enum case '.$spec->caseName.' on non-enum class '.$enum->name
            );
        }
        $result = new Variable();
        if (!EnumCaseSupport::tryMaterializeEnumCaseConstantFetch(
            $enum,
            strtolower($spec->caseName),
            $result
        )) {
            throw new \Error(self::enumCaseNotFoundMessage($enum->name, $spec->caseName));
        }

        return $result;
    }

    /**
     * @param list<Variable> $invokeArgs
     */
    public static function invokeAttributeConstructor(
        VmEngine $vm,
        Context $ctx,
        Func $ctor,
        Variable $thisVar,
        array $invokeArgs,
    ): void {
        if ($ctor instanceof Func\PHP) {
            $vm->invokePhpFunction($ctor, $thisVar, ...$invokeArgs);

            return;
        }
        if ($ctor instanceof Func\Internal) {
            $frame = $ctor->getFrame($ctx);
            $frame->vmContext = $ctx;
            $frame->calledArgs = array_merge([$thisVar], $invokeArgs);
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
                AttributeConstruct::class => ['flags'],
                default => [],
            };
        }

        return [];
    }

    /**
     * Map stored attribute ctor args to invokePhpFunction arguments in parameter order (#3216).
     *
     * @param list<array{name: ?string, value: mixed}> $argSpecs
     *
     * @return list<Variable>
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
        foreach (self::constructorParamNames($ctor) as $paramName) {
            if (isset($named[$paramName])) {
                $vars[] = self::attributeValueToVariable($named[$paramName], $ctx);
            } elseif (array_key_exists($pi, $positional)) {
                $vars[] = self::attributeValueToVariable($positional[$pi++], $ctx);
            } else {
                $null = new Variable();
                $null->null();
                $vars[] = $null;
            }
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
                        Variable::TYPE_OBJECT, Variable::TYPE_ENUM_CASE => $resolved,
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

    public static function requireReflectionClass(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('ReflectionClass method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== self::REFLECTION_CLASS) {
            throw new \LogicException('Expected ReflectionClass instance');
        }

        return $obj;
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

    public static function classNameFromReflection(ObjectEntry $reflection): string
    {
        $nameVar = $reflection->getProperty(self::PROP_CLASS_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionClass missing target class name');
        }

        return $nameVar->toString();
    }

    /**
     * ReflectionClass::newLazyGhost/Proxy — class name string or ReflectionClass receiver (#6399).
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

    public static function enumCaseNameFromReflection(ObjectEntry $reflection): string
    {
        $nameVar = $reflection->getProperty(self::PROP_ENUM_CASE_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionEnumUnitCase missing case name');
        }

        return $nameVar->toString();
    }

    public static function methodNameFromReflection(ObjectEntry $reflection): string
    {
        $nameVar = $reflection->getProperty(self::PROP_METHOD_NAME)->resolveIndirect();
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
        if (self::REFLECTION_CLASS_CONSTANT !== $classLc && self::REFLECTION_CONSTANT !== $classLc) {
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

    public static function functionNameFromReflection(ObjectEntry $reflection): string
    {
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

    public static function isReflectionInternalFunction(ObjectEntry $reflection): bool
    {
        return $reflection->reflectionIsInternalFunction;
    }

    /**
     * Resolve a named function for ReflectionFunction::__construct (user or internal).
     *
     * php-src: ext/reflection/php_reflection.c — zend_lookup_internal_function()
     */
    public static function resolveFunctionForReflection(Context $ctx, string $functionName): Func
    {
        $lc = strtolower($functionName);
        $func = $ctx->functions[$lc] ?? null;
        if (null === $func) {
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
            return $closure->func;
        }

        return self::resolveUserFunction($ctx, self::functionNameFromReflection($reflection));
    }

    public static function constantNameFromReflection(ObjectEntry $reflection): string
    {
        $nameVar = $reflection->getProperty(self::PROP_CONSTANT_NAME)->resolveIndirect();
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
        $classNameVar = $reflection->getProperty(self::PROP_CLASS_NAME)->resolveIndirect();
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
        $func = self::resolveUserFunction($ctx, $funcNameVar->toString());
        $index = self::paramIndexFromReflection($reflection);
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

    /**
     * php-src: reflection_method_has_tentative_return_type() (#6597).
     *
     * User-declared methods in this compiler always store explicit return types on the
     * declaring Func; inherited ZEND_TYPE_IS_TENTATIVE is not modeled yet — false for VM users.
     */
    public static function reflectedMethodHasTentativeReturnType(Context $ctx, ObjectEntry $reflection): bool
    {
        self::resolveReflectedMethod($ctx, $reflection);

        return false;
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
     * @param list<Variable> $invokeArgs
     */
    public static function invokeReflectedMethod(
        VmEngine $vm,
        Frame $frame,
        ObjectEntry $reflection,
        Variable $objectArg,
        array $invokeArgs
    ): Variable {
        $ctx = VmReflection::requireContext($frame);
        [$declaring, $methodLc, $func] = self::resolveReflectedMethod($ctx, $reflection);
        $methodName = $declaring->methodNames[$methodLc] ?? self::methodNameFromReflection($reflection);
        if (!$func instanceof Func\PHP) {
            throw new \LogicException("{$declaring->name}::{$methodName}() is not a user method in this compiler build");
        }
        if (self::methodIsStatic($func)) {
            return $vm->invokeStaticWithCalledScope($declaring->name, $methodName, ...$invokeArgs);
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

        return $vm->invokeInstanceMethod($objectArg->toObject(), $methodName, ...$invokeArgs);
    }

    /**
     * @return list<Variable>
     */
    public static function invokeArgsFromArray(Variable $argsVar, string $methodLabel): array
    {
        $argsVar = $argsVar->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $argsVar->type) {
            throw new \TypeError(
                $methodLabel.'(): Argument #2 ($args) must be of type array, '
                .self::valueTypeLabel($argsVar).' given'
            );
        }
        $invokeArgs = [];
        foreach ($argsVar->toArray()->iterate(true) as $value) {
            $copy = new Variable();
            $copy->copyFrom($value);
            $invokeArgs[] = $copy;
        }

        return $invokeArgs;
    }

    private static function methodIsStatic(Func $func): bool
    {
        if (!$func instanceof Func\PHP) {
            return false;
        }
        $decl = $func->block->func;

        return null !== $decl && (($decl->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) !== 0;
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
        if (!isset($entry->methods[$methodLc]) && !isset($entry->abstractMethods[$methodLc])) {
            self::throwReflectionException(self::methodNotFoundMessage($entry->name, $methodName));
        }

        return [$entry, $methodName];
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
        $rm->getProperty(self::PROP_CLASS_NAME)->string($entry->name);
        $rm->getProperty(self::PROP_METHOD_NAME)->string($methodName);

        return $rm;
    }

    public static function methodSourceLocation(ClassEntry $entry, string $methodLc): ?SourceLocation
    {
        return $entry->methodSourceLocations[$methodLc] ?? null;
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
        $returnVar->string('Core');
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
        $rf->getProperty(self::PROP_FUNC_NAME)->string($functionName);

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
     * @param list<Variable> $invokeArgs
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
            return $vm->invokeClosure($closure, ...$invokeArgs);
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

        return $vm->invokePhpFunction($func, ...$invokeArgs);
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
        $rf->getProperty(self::PROP_FUNC_NAME)->string(self::displayNameForClosureState($state));

        return $rf;
    }

    private static function displayNameForClosureState(ClosureState $state): string
    {
        if (null !== $state->methodName && '' !== $state->methodName) {
            $scope = $state->boundScopeClass ?? '';
            if ('' === $scope && null !== $state->methodReceiver) {
                $recv = $state->methodReceiver->resolveIndirect();
                if (Variable::TYPE_OBJECT === $recv->type) {
                    $scope = $recv->toObject()->class->name;
                }
            }
            if ('' !== $scope) {
                return $scope.'::'.$state->methodName;
            }

            return $state->methodName;
        }

        return $state->func->getName();
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    public static function isReflectionClosure(ObjectEntry $reflection): bool
    {
        return null !== $reflection->reflectionClosureState;
    }

    public static function newReflectionClassObjectForName(Context $ctx, string $className): ObjectEntry
    {
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            self::throwReflectionException('Class '.$className.' does not exist');
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

    /** php-src: closure_func->common.scope (definition site). */
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
        $cfgFunc = $state->func->block->func ?? null;
        if (null === $cfgFunc || null === $cfgFunc->class || null === $cfgFunc->class->value || '' === $cfgFunc->class->value) {
            return null;
        }

        return $cfgFunc->class->value;
    }

    /** php-src: get_closure called_scope, else definition scope. */
    public static function closureCalledScopeClassName(ClosureState $state): ?string
    {
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
        $ht = $returnVar->toArray();
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

        return [null, null];
    }
}
