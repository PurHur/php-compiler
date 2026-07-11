<?php

declare(strict_types=1);

namespace PHPCompiler\ext\random;

use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\EnumSupport;
use PHPCompiler\VM\Variable;

/**
 * Register ext/random builtin enums (php-src ext/random/random.stub.php; issue #11551).
 */
final class BuiltinEnums
{
    public static function register(Context $ctx): void
    {
        if (!CompilerVersion::supportsRandomIntervalBoundary()) {
            return;
        }

        $before = array_keys($ctx->classes);
        self::registerIntervalBoundary($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    /**
     * PHP 8.3+ Random\IntervalBoundary unit enum for Randomizer::getFloat() (#11551).
     *
     * php-src: ext/random/random.stub.php — enum IntervalBoundary
     */
    private static function registerIntervalBoundary(Context $ctx): void
    {
        $lc = 'random\\intervalboundary';
        if (isset($ctx->classes[$lc])) {
            return;
        }

        $entry = new ClassEntry('Random\\IntervalBoundary');
        $entry->isEnum = true;

        foreach (['ClosedOpen', 'ClosedClosed', 'OpenClosed', 'OpenOpen'] as $name) {
            self::registerUnitEnumCase($entry, $name);
        }

        EnumSupport::ensureBuiltinCasesMethod($entry);
        EnumSupport::ensureBuiltinEnumInterfaces($entry);

        $ctx->classes[$lc] = $entry;
        $ctx->enums[$lc] = true;
    }

    private static function registerUnitEnumCase(ClassEntry $enum, string $name): void
    {
        $lc = strtolower($name);
        $dummy = new Variable();
        $case = EnumCaseSupport::createCase($enum, $name, $dummy);
        $enum->constants[$lc] = $case;
        $enum->enumCaseCanonicalNames[$lc] = $name;
        $enum->enumCases[] = [
            'name' => $name,
            'value' => null,
        ];
    }
}
