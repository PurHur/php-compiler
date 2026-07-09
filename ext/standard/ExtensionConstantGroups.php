<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\ext\calendar\CalendarConstants;
use PHPCompiler\ext\curl\CurlConstants;
use PHPCompiler\ext\dom\DomExceptionConstants;
use PHPCompiler\ext\filter\FilterConstants;
use PHPCompiler\ext\hash\MhashRegistry;
use PHPCompiler\ext\intl\IntlConstants;
use PHPCompiler\ext\libxml\LibxmlConstants;
use PHPCompiler\ext\mbstring\MbstringConstants;
use PHPCompiler\ext\openssl\OpensslConstants;
use PHPCompiler\ext\posix\PosixConstants;
use PHPCompiler\ext\session\SessionConstants;
use PHPCompiler\ext\sodium\SodiumConstants;
use PHPCompiler\ext\tokenizer\TokenConstants;

/**
 * Extension constant groups for get_defined_constants(true) (Zend/zend_builtin_functions.c; #17416).
 */
final class ExtensionConstantGroups
{
    /** @var array<string, true>|null */
    private static ?array $registeredNameSet = null;

    /**
     * Extension name => constant name => fallback scalar for bucket materialization.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function groups(): array
    {
        $groups = StdlibModuleConstants::categorizedBootstrapConstants();
        $groups['calendar'] = CalendarConstants::registeredConstants();
        $groups['filter'] = FilterConstants::REGISTERED;
        $groups['tokenizer'] = TokenConstants::registeredConstants();
        $groups['dom'] = DomExceptionConstants::globalConstants();
        $groups['libxml'] = LibxmlConstants::registeredConstants();
        $groups['openssl'] = OpensslConstants::registeredConstants();
        $groups['posix'] = PosixConstants::registeredConstants();
        $groups['session'] = SessionConstants::registeredConstants();
        $groups['mbstring'] = MbstringConstants::registeredConstants();
        $groups['hash'] = MhashRegistry::constants();
        $groups['curl'] = CurlConstants::registeredConstants();
        $groups['intl'] = IntlConstants::registeredConstants();
        if (\PHPCompiler\ext\sodium\SodiumExtensionPolicy::advertisesExtension()) {
            $groups['sodium'] = SodiumConstants::registeredConstants();
        }

        return $groups;
    }

    /** @return array<string, true> */
    public static function registeredNameSet(): array
    {
        if (null !== self::$registeredNameSet) {
            return self::$registeredNameSet;
        }
        $set = [];
        foreach (self::groups() as $constants) {
            foreach (array_keys($constants) as $name) {
                $set[$name] = true;
            }
        }
        foreach (StdlibModuleConstants::CORE_BUCKET_NAMES as $name) {
            $set[$name] = true;
        }
        self::$registeredNameSet = $set;

        return self::$registeredNameSet;
    }

    public static function isExtensionConstantName(string $name): bool
    {
        return isset(self::registeredNameSet()[$name]);
    }

    /** @return list<string> */
    public static function coreBucketNames(): array
    {
        return StdlibModuleConstants::CORE_BUCKET_NAMES;
    }
}
