<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\Builtin\EnumCases;

/** Helpers for user enum runtime (#1356, #3308). */
final class EnumSupport
{
    public static function ensureBuiltinCasesMethod(ClassEntry $entry): void
    {
        if (!$entry->isEnum) {
            return;
        }
        $entry->methods['cases'] = new EnumCases($entry);
        $entry->methodVisibility['cases'] = CfgFunc::FLAG_PUBLIC | CfgFunc::FLAG_STATIC;
        $entry->methodNames['cases'] ??= 'cases';
    }

    /** Zend implicit UnitEnum / BackedEnum on all enums (#3550). */
    public static function ensureBuiltinEnumInterfaces(ClassEntry $entry): void
    {
        if (!in_array('unitenum', $entry->interfaces, true)) {
            $entry->interfaces[] = 'unitenum';
        }
        if (null !== $entry->backedType && !in_array('backedenum', $entry->interfaces, true)) {
            $entry->interfaces[] = 'backedenum';
        }
    }
}
