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

    /**
     * Zend {@see zend_enum_build_backed_enum_table()} — lazy on first case use (#5672).
     *
     * @throws \Error duplicate backing scalar
     */
    public static function ensureBackedEnumValuesUnique(ClassEntry $entry): void
    {
        if (!$entry->isEnum || null === $entry->backedType || $entry->backedEnumTableBuilt) {
            return;
        }
        $backedType = $entry->backedType;
        /** @var array<int, string>|array<string, string> */
        $seen = [];
        foreach ($entry->enumCases as $case) {
            $backing = BackedEnum::caseBackingScalar($backedType, $case['value'])->resolveIndirect();
            if ('int' === $backedType) {
                if (Variable::TYPE_INTEGER !== $backing->type) {
                    throw new \LogicException('Backed enum case requires int backing value');
                }
                $key = $backing->toInt();
            } else {
                if (Variable::TYPE_STRING !== $backing->type) {
                    throw new \LogicException('Backed enum case requires string backing value');
                }
                $key = $backing->toString();
            }
            if (isset($seen[$key])) {
                throw new \Error(sprintf(
                    'Duplicate value in enum %s for cases %s and %s',
                    $entry->name,
                    $seen[$key],
                    $case['name']
                ));
            }
            $seen[$key] = $case['name'];
        }
        $entry->backedEnumTableBuilt = true;
    }
}
