<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Compiler\AttributeEntry;
use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\AttributeSupport;
use PHPCompiler\VM\Builtin\DeprecatedConstruct;
use PHPCompiler\VM\Builtin\NoDiscardConstruct;
use PHPCompiler\VM\Builtin\OverrideConstruct;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * Register ext/standard builtin attribute classes (php-src Zend/zend_attributes.stub.php; #7145, #7147, #7238).
 *
 * Base `Attribute` class remains in lib/VM/AttributeSupport.php.
 */
final class BuiltinAttributes
{
    public static function register(Context $ctx): void
    {
        if (!isset($ctx->classes[AttributeSupport::CLASS_ATTRIBUTE])) {
            return;
        }

        $before = array_keys($ctx->classes);
        self::registerReturnTypeWillChange($ctx);
        self::registerAllowDynamicProperties($ctx);
        self::registerSensitiveParameter($ctx);
        if (CompilerVersion::advertisesOverrideAttributeClass()) {
            self::registerOverride($ctx);
        }
        if (CompilerVersion::advertisesDeprecatedAttributeClass()) {
            self::registerDeprecated($ctx);
        }
        if (CompilerVersion::advertisesNoDiscardAttributeClass()) {
            self::registerNoDiscard($ctx);
        }
        if (CompilerVersion::advertisesDelayedTargetValidationAttributeClass()) {
            self::registerDelayedTargetValidation($ctx);
        }
        if (CompilerVersion::advertisesCompileTimeAttributeClass()) {
            self::registerCompileTime($ctx);
        }
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    private static function registerReturnTypeWillChange(Context $ctx): void
    {
        self::registerBuiltinAttributeClass(
            $ctx,
            'ReturnTypeWillChange',
            AttributeSupport::CLASS_RETURN_TYPE_WILL_CHANGE,
            AttributeSupport::TARGET_METHOD
        );
    }

    private static function registerAllowDynamicProperties(Context $ctx): void
    {
        self::registerBuiltinAttributeClass(
            $ctx,
            'AllowDynamicProperties',
            AttributeSupport::CLASS_ALLOW_DYNAMIC_PROPERTIES,
            AttributeSupport::TARGET_CLASS
        );
    }

    private static function registerSensitiveParameter(Context $ctx): void
    {
        self::registerBuiltinAttributeClass(
            $ctx,
            'SensitiveParameter',
            AttributeSupport::CLASS_SENSITIVE_PARAMETER,
            AttributeSupport::TARGET_PARAMETER
        );
    }

    private static function registerOverride(Context $ctx): void
    {
        self::registerBuiltinAttributeClass(
            $ctx,
            'Override',
            AttributeSupport::CLASS_OVERRIDE,
            AttributeSupport::TARGET_METHOD
                | AttributeSupport::TARGET_CLASS_CONSTANT
                | AttributeSupport::TARGET_PROPERTY
        );
    }

    private static function registerDeprecated(Context $ctx): void
    {
        $strProto = new Variable(Variable::TYPE_STRING);
        $pub = CfgFunc::FLAG_PUBLIC;

        $entry = new ClassEntry('Deprecated');
        $entry->parentLc = AttributeSupport::CLASS_ATTRIBUTE;
        $entry->properties[] = new ClassProperty('message', null, $strProto, true);
        $entry->properties[] = new ClassProperty('since', null, $strProto, true);
        $entry->constructor = new DeprecatedConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        $targets = AttributeSupport::TARGET_METHOD
            | AttributeSupport::TARGET_FUNCTION
            | AttributeSupport::TARGET_CLASS_CONSTANT;
        $entry->attributeNames = ['Attribute'];
        $entry->attributeEntries = [
            new AttributeEntry('Attribute', [['name' => null, 'value' => $targets]]),
        ];

        $ctx->classes[AttributeSupport::CLASS_DEPRECATED] = $entry;
    }

    private static function registerNoDiscard(Context $ctx): void
    {
        $strProto = new Variable(Variable::TYPE_STRING);
        $pub = CfgFunc::FLAG_PUBLIC;

        $entry = new ClassEntry('NoDiscard');
        $entry->parentLc = AttributeSupport::CLASS_ATTRIBUTE;
        $entry->properties[] = new ClassProperty('message', null, $strProto, true);
        $entry->constructor = new NoDiscardConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        $targets = AttributeSupport::TARGET_FUNCTION | AttributeSupport::TARGET_METHOD;
        $entry->attributeNames = ['Attribute'];
        $entry->attributeEntries = [
            new AttributeEntry('Attribute', [['name' => null, 'value' => $targets]]),
        ];

        $ctx->classes[AttributeSupport::CLASS_NODISCARD] = $entry;
    }

    private static function registerDelayedTargetValidation(Context $ctx): void
    {
        self::registerMarkerAttributeClass(
            $ctx,
            'DelayedTargetValidation',
            AttributeSupport::CLASS_DELAYED_TARGET_VALIDATION,
            AttributeSupport::TARGET_ALL
        );
    }

    private static function registerCompileTime(Context $ctx): void
    {
        $targets = AttributeSupport::TARGET_CLASS_CONSTANT | AttributeSupport::TARGET_CONSTANT;
        self::registerMarkerAttributeClass(
            $ctx,
            'CompileTime',
            AttributeSupport::CLASS_COMPILE_TIME,
            $targets
        );
    }

    private static function registerMarkerAttributeClass(
        Context $ctx,
        string $name,
        string $lcKey,
        int $targets
    ): void {
        $entry = new ClassEntry($name);
        $entry->parentLc = AttributeSupport::CLASS_ATTRIBUTE;
        $entry->attributeNames = ['Attribute'];
        $entry->attributeEntries = [
            new AttributeEntry('Attribute', [['name' => null, 'value' => $targets]]),
        ];

        $ctx->classes[$lcKey] = $entry;
    }

    private static function registerBuiltinAttributeClass(
        Context $ctx,
        string $name,
        string $lcKey,
        int $targets
    ): void {
        $pub = CfgFunc::FLAG_PUBLIC;

        $entry = new ClassEntry($name);
        $entry->parentLc = AttributeSupport::CLASS_ATTRIBUTE;
        $entry->constructor = new OverrideConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        $entry->attributeNames = ['Attribute'];
        $entry->attributeEntries = [
            new AttributeEntry('Attribute', [['name' => null, 'value' => $targets]]),
        ];

        $ctx->classes[$lcKey] = $entry;
    }
}
