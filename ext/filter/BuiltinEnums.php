<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\EnumSupport;
use PHPCompiler\VM\Variable;

/**
 * Register ext/filter builtin enums (php-src ext/filter/filter.stub.php; issue #7284).
 */
final class BuiltinEnums
{
    public static function register(Context $ctx): void
    {
        if (!CompilerVersion::supportsBuiltinStubEnums()) {
            return;
        }

        $before = array_keys($ctx->classes);
        self::registerPhpInputFilter($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    /**
     * PHP 8.4 PhpInputFilter: int-backed enum for filter_input() type (#7284).
     *
     * php-src: ext/filter/filter.stub.php — enum PhpInputFilter: int
     */
    private static function registerPhpInputFilter(Context $ctx): void
    {
        if (isset($ctx->classes['phpinputfilter'])) {
            return;
        }

        $entry = new ClassEntry('PhpInputFilter');
        $entry->isEnum = true;
        $entry->backedType = 'int';

        self::registerBackedEnumCase($entry, 'Post', VmFilter::INPUT_POST);
        self::registerBackedEnumCase($entry, 'Get', VmFilter::INPUT_GET);
        self::registerBackedEnumCase($entry, 'Cookie', VmFilter::INPUT_COOKIE);
        self::registerBackedEnumCase($entry, 'Env', VmFilter::INPUT_ENV);
        self::registerBackedEnumCase($entry, 'Server', VmFilter::INPUT_SERVER);
        self::registerBackedEnumCase($entry, 'Session', VmFilter::INPUT_SESSION);

        EnumSupport::ensureBuiltinCasesMethod($entry);
        EnumSupport::ensureBuiltinEnumInterfaces($entry);

        $lc = 'phpinputfilter';
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
