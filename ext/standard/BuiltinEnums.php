<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\EnumSupport;
use PHPCompiler\VM\Variable;

/**
 * Register ext/standard builtin enums (php-src Zend/zend_enum.def; issue #7222).
 */
final class BuiltinEnums
{
    public static function register(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        self::registerPropertyHookType($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    /**
     * PHP 8.4 PropertyHookType: int-backed enum for property hook reflection (#7222).
     *
     * php-src: Zend/zend_enum.def — register_property_hook_type_enum
     */
    private static function registerPropertyHookType(Context $ctx): void
    {
        if (isset($ctx->classes['propertyhooktype'])) {
            return;
        }

        $entry = new ClassEntry('PropertyHookType');
        $entry->isEnum = true;
        $entry->backedType = 'int';

        self::registerBackedEnumCase($entry, 'Get', 0);
        self::registerBackedEnumCase($entry, 'Set', 1);

        EnumSupport::ensureBuiltinCasesMethod($entry);
        EnumSupport::ensureBuiltinEnumInterfaces($entry);

        $lc = 'propertyhooktype';
        $ctx->classes[$lc] = $entry;
        $ctx->enums[$lc] = true;
    }

    private static function registerBackedEnumCase(ClassEntry $enum, string $name, int $value): void
    {
        $lc = strtolower($name);
        $backing = new Variable();
        $backing->int($value);
        $case = EnumCaseSupport::createCase($enum, $name, $backing);
        $enum->constants[$lc] = $case;
        $enum->enumCaseCanonicalNames[$lc] = $name;
        $enum->enumCases[] = [
            'name' => $name,
            'value' => $backing,
        ];
    }
}
