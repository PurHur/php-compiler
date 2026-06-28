<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Compiler\AttributeEntry;
use PHPCompiler\VM\Builtin\AttributeConstruct;

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

    public const CLASS_NODISCARD = 'nodiscard';

    public const CLASS_DELAYED_TARGET_VALIDATION = 'delayedtargetvalidation';

    public const CLASS_COMPILE_TIME = 'compiletime';

    public const CLASS_ENUM_CASES = 'enumcases';

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
        $ctx->classes[self::CLASS_ATTRIBUTE]->isInternal = true;
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
}
