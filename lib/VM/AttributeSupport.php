<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Compiler\AttributeEntry;
use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\Builtin\AttributeConstruct;
use PHPCompiler\VM\Builtin\DeprecatedConstruct;
use PHPCompiler\VM\Builtin\OverrideConstruct;

/**
 * Builtin Attribute / Override classes (Zend zend_attributes.c, issues #5142, #5937, #6303).
 */
final class AttributeSupport
{
    public const CLASS_ATTRIBUTE = 'attribute';

    public const CLASS_OVERRIDE = 'override';

    public const CLASS_RETURN_TYPE_WILL_CHANGE = 'returntypewillchange';

    public const CLASS_ALLOW_DYNAMIC_PROPERTIES = 'allowdynamicproperties';

    public const CLASS_SENSITIVE_PARAMETER = 'sensitiveparameter';

    public const CLASS_DEPRECATED = 'deprecated';

    /** Zend ZEND_ATTRIBUTE_TARGET_* flags (zend_attributes.h). */
    public const TARGET_CLASS = 1;

    public const TARGET_FUNCTION = 2;

    public const TARGET_METHOD = 4;

    public const TARGET_PROPERTY = 8;

    public const TARGET_CLASS_CONSTANT = 16;

    public const TARGET_PARAMETER = 32;

    public const TARGET_CONSTANT = 64;

    public const TARGET_ALL = 127;

    public const IS_REPEATABLE = 128;

    public static function register(Context $ctx): void
    {
        self::registerAttribute($ctx);
        self::registerReturnTypeWillChange($ctx);
        self::registerAllowDynamicProperties($ctx);
        self::registerSensitiveParameter($ctx);
        self::registerDeprecated($ctx);
        if (CompilerVersion::supportsOverrideAttribute()) {
            self::registerOverride($ctx);
        }
        $ctx->classes[self::CLASS_ATTRIBUTE]->isInternal = true;
        $ctx->classes[self::CLASS_RETURN_TYPE_WILL_CHANGE]->isInternal = true;
        $ctx->classes[self::CLASS_ALLOW_DYNAMIC_PROPERTIES]->isInternal = true;
        $ctx->classes[self::CLASS_SENSITIVE_PARAMETER]->isInternal = true;
        $ctx->classes[self::CLASS_DEPRECATED]->isInternal = true;
        if (CompilerVersion::supportsOverrideAttribute()) {
            $ctx->classes[self::CLASS_OVERRIDE]->isInternal = true;
        }
    }

    private static function registerAttribute(Context $ctx): void
    {
        $intProto = new Variable(Variable::TYPE_INTEGER);
        $pub = CfgFunc::FLAG_PUBLIC;

        $entry = new ClassEntry('Attribute');
        $entry->properties[] = new ClassProperty('flags', null, $intProto);
        $entry->constructor = new AttributeConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        foreach (
            [
                'target_class' => self::TARGET_CLASS,
                'target_function' => self::TARGET_FUNCTION,
                'target_method' => self::TARGET_METHOD,
                'target_property' => self::TARGET_PROPERTY,
                'target_class_constant' => self::TARGET_CLASS_CONSTANT,
                'target_parameter' => self::TARGET_PARAMETER,
                'target_constant' => self::TARGET_CONSTANT,
                'target_all' => self::TARGET_ALL,
                'is_repeatable' => self::IS_REPEATABLE,
            ] as $name => $value
        ) {
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$name] = $const;
            $entry->constNames[$name] = strtoupper($name);
        }

        $entry->attributeNames = ['Attribute'];
        $entry->attributeEntries = [
            new AttributeEntry('Attribute', [['name' => null, 'value' => self::TARGET_CLASS]]),
        ];

        $ctx->classes[self::CLASS_ATTRIBUTE] = $entry;
    }

    private static function registerReturnTypeWillChange(Context $ctx): void
    {
        self::registerBuiltinAttributeClass(
            $ctx,
            'ReturnTypeWillChange',
            self::CLASS_RETURN_TYPE_WILL_CHANGE,
            self::TARGET_METHOD
        );
    }

    private static function registerAllowDynamicProperties(Context $ctx): void
    {
        self::registerBuiltinAttributeClass(
            $ctx,
            'AllowDynamicProperties',
            self::CLASS_ALLOW_DYNAMIC_PROPERTIES,
            self::TARGET_CLASS
        );
    }

    private static function registerSensitiveParameter(Context $ctx): void
    {
        self::registerBuiltinAttributeClass(
            $ctx,
            'SensitiveParameter',
            self::CLASS_SENSITIVE_PARAMETER,
            self::TARGET_PARAMETER
        );
    }

    private static function registerOverride(Context $ctx): void
    {
        self::registerBuiltinAttributeClass(
            $ctx,
            'Override',
            self::CLASS_OVERRIDE,
            self::TARGET_METHOD | self::TARGET_PROPERTY
        );
    }

    private static function registerDeprecated(Context $ctx): void
    {
        $strProto = new Variable(Variable::TYPE_STRING);
        $pub = CfgFunc::FLAG_PUBLIC;

        $entry = new ClassEntry('Deprecated');
        $entry->parentLc = self::CLASS_ATTRIBUTE;
        $entry->properties[] = new ClassProperty('message', null, $strProto, true);
        $entry->properties[] = new ClassProperty('since', null, $strProto, true);
        $entry->constructor = new DeprecatedConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        $targets = self::TARGET_METHOD | self::TARGET_FUNCTION | self::TARGET_CLASS_CONSTANT;
        $entry->attributeNames = ['Attribute'];
        $entry->attributeEntries = [
            new AttributeEntry('Attribute', [['name' => null, 'value' => $targets]]),
        ];

        $ctx->classes[self::CLASS_DEPRECATED] = $entry;
    }

    private static function registerBuiltinAttributeClass(
        Context $ctx,
        string $name,
        string $lcKey,
        int $targets
    ): void {
        $pub = CfgFunc::FLAG_PUBLIC;

        $entry = new ClassEntry($name);
        $entry->parentLc = self::CLASS_ATTRIBUTE;
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
