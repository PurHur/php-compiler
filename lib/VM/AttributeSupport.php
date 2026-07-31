<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Compiler\AttributeEntry;
use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\Builtin\AttributeConstruct;

/**
 * Builtin Attribute / Override classes (Zend zend_attributes.c, issues #5142, #5937, #6303, #20727).
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

    /**
     * ZEND_ATTRIBUTE_TARGET_CONST (1<<6) — userland constant only on PHP 8.5+.
     *
     * On ≤8.4 the same bit is IS_REPEATABLE; use {@see hasTargetConstant()} / {@see targetAll()}.
     */
    public const TARGET_CONSTANT = 64;

    /** TARGET_ALL without TARGET_CONSTANT (≤8.4 / zend_attributes.h ((1<<6)-1)). */
    public const TARGET_ALL_PRE85 = 63;

    /** TARGET_ALL including TARGET_CONSTANT (8.5+ / ((1<<7)-1)). */
    public const TARGET_ALL_85 = 127;

    /** IS_REPEATABLE on ≤8.4 (1<<6). */
    public const IS_REPEATABLE_PRE85 = 64;

    /** IS_REPEATABLE on 8.5+ (1<<7). */
    public const IS_REPEATABLE_85 = 128;

    /** Profile-aware TARGET_ALL (63 on ≤8.4, 127 on 8.5+). */
    public static function targetAll(): int
    {
        return CompilerVersion::supportsAttributeTargetConstant()
            ? self::TARGET_ALL_85
            : self::TARGET_ALL_PRE85;
    }

    /** Profile-aware IS_REPEATABLE flag bit. */
    public static function isRepeatableFlag(): int
    {
        return CompilerVersion::supportsAttributeTargetConstant()
            ? self::IS_REPEATABLE_85
            : self::IS_REPEATABLE_PRE85;
    }

    public static function hasTargetConstant(): bool
    {
        return CompilerVersion::supportsAttributeTargetConstant();
    }

    /**
     * Resolve Attribute::* class constant for compile-time fold / registration (#20727).
     *
     * Never consult host {@see \Attribute} — profile may disagree with the Zend running the compiler.
     */
    public static function builtinConstValue(string $lcConst): ?int
    {
        return match ($lcConst) {
            'target_class' => self::TARGET_CLASS,
            'target_function' => self::TARGET_FUNCTION,
            'target_method' => self::TARGET_METHOD,
            'target_property' => self::TARGET_PROPERTY,
            'target_class_constant' => self::TARGET_CLASS_CONSTANT,
            'target_parameter' => self::TARGET_PARAMETER,
            'target_constant' => self::hasTargetConstant() ? self::TARGET_CONSTANT : null,
            'target_all' => self::targetAll(),
            'is_repeatable' => self::isRepeatableFlag(),
            default => null,
        };
    }

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
        // php-src Zend/zend_attributes.c — ZEND_ACC_FINAL; stub: final class Attribute (#21669).
        $entry->isFinal = true;
        $entry->properties[] = new ClassProperty('flags', null, $intProto);
        $entry->constructor = new AttributeConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        $consts = [
            'target_class' => self::TARGET_CLASS,
            'target_function' => self::TARGET_FUNCTION,
            'target_method' => self::TARGET_METHOD,
            'target_property' => self::TARGET_PROPERTY,
            'target_class_constant' => self::TARGET_CLASS_CONSTANT,
            'target_parameter' => self::TARGET_PARAMETER,
        ];
        if (self::hasTargetConstant()) {
            $consts['target_constant'] = self::TARGET_CONSTANT;
        }
        $consts['target_all'] = self::targetAll();
        $consts['is_repeatable'] = self::isRepeatableFlag();

        foreach ($consts as $name => $value) {
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $canonical = strtoupper($name);
            $entry->constants[$canonical] = $const;
            $entry->constNames[$canonical] = $canonical;
        }

        $entry->attributeNames = ['Attribute'];
        $entry->attributeEntries = [
            new AttributeEntry('Attribute', [['name' => null, 'value' => self::TARGET_CLASS]]),
        ];

        $ctx->classes[self::CLASS_ATTRIBUTE] = $entry;
    }
}
