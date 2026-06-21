<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Compiler\EnumBackedCaseCheck;
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
     * Map compile-time enum case stubs to the live {@see ClassEntry} in VM context (#5773).
     */
    public static function resolveRuntimeEnumClass(?Context $context, ClassEntry $entry): ClassEntry
    {
        if (null === $context) {
            return $entry;
        }
        $lc = strtolower(ltrim($entry->name, '\\'));
        if (isset($context->classes[$lc]) && $context->classes[$lc]->isEnum) {
            return $context->classes[$lc];
        }

        return $entry;
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
        $cases = [];
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
            $cases[] = ['name' => $case['name'], 'backing' => $key];
        }
        $message = EnumBackedCaseCheck::duplicateBackingErrorMessage($entry->name, $cases);
        if (null !== $message) {
            throw new \Error($message);
        }
        $entry->backedEnumTableBuilt = true;
    }

    /**
     * Enum::cases() / UnitEnum::cases() result — declaration-order dense list (#3308, #10395).
     *
     * php-src: Zend/zend_enum.c — zend_enum_list_cases
     */
    public static function casesList(ClassEntry $enum, ?Context $context): Variable
    {
        $enum = self::resolveRuntimeEnumClass($context, $enum);
        self::ensureBackedEnumValuesUnique($enum);
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach (self::enumCaseNamesInOrder($enum) as $index => $caseName) {
            $ht->addIndex($index, self::materializeCaseForCasesList($enum, $caseName));
        }

        return $result;
    }

    /**
     * Single enum case object for cases() list entries — shared with {@see Builtin\EnumCases}.
     */
    public static function materializeCaseForCasesList(ClassEntry $enum, string $caseName): Variable
    {
        $canonical = BackedEnum::canonicalCaseVariable($enum, $caseName);
        if (null !== $canonical) {
            $caseVar = new Variable();
            $caseVar->copyFrom($canonical->resolveIndirect());

            return $caseVar;
        }
        $backing = new Variable(Variable::TYPE_NULL);
        $backing->null();
        if (null !== $enum->backedType) {
            $memberLc = strtolower($caseName);
            if (isset($enum->constants[$memberLc])) {
                $backing->copyFrom(
                    BackedEnum::caseBackingScalar($enum->backedType, $enum->constants[$memberLc])
                );
            }
        }

        return EnumCaseSupport::createCase($enum, $caseName, $backing);
    }

    /**
     * Enum case names in declaration order — {@see ClassEntry::$enumCases} or constants fallback (#9682, #9603).
     *
     * @return list<string>
     */
    public static function enumCaseNamesInOrder(ClassEntry $entry): array
    {
        if ([] !== $entry->enumCases) {
            $names = [];
            foreach ($entry->enumCases as $case) {
                $names[] = $case['name'];
            }

            return $names;
        }
        if ([] !== $entry->enumCaseCanonicalNames) {
            return array_values($entry->enumCaseCanonicalNames);
        }
        $names = [];
        foreach ($entry->constants as $memberLc => $_stored) {
            $caseName = self::enumCaseNameForConstantMember($entry, $memberLc);
            if (null !== $caseName) {
                $names[] = $caseName;
            }
        }

        return $names;
    }

    /**
     * Enum `case` member name for a constants-table key, or null for user `const` (#5832, #5054).
     */
    public static function enumCaseNameForConstantMember(ClassEntry $enum, string $memberLc): ?string
    {
        if (!$enum->isEnum) {
            return null;
        }
        if (isset($enum->enumCaseCanonicalNames[$memberLc])) {
            return $enum->enumCaseCanonicalNames[$memberLc];
        }
        foreach ($enum->enumCases as $case) {
            if (strtolower($case['name']) === $memberLc) {
                return $case['name'];
            }
        }

        return null;
    }
}
