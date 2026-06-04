<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\Compiler\AttributeEntry;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Compile-time attribute tables for JIT/AOT reflection (#1936, #5621).
 *
 * Replaces lib/AOT/runtime/phpc_attr_registry.c with LLVM lowered from PHP.
 */
final class AttributeRegistryLowering
{
    /** @var array<string, list<string>> */
    private static array $classNames = [];

    /** @var array<string, list<AttributeEntry>> */
    private static array $classEntries = [];

    /** @var array<string, array<string, list<string>>> */
    private static array $methodNames = [];

    private static int $seq = 0;

    /**
     * @param list<string>|list<AttributeEntry> $namesOrEntries
     */
    public static function recordClass(string $classLc, array $namesOrEntries): void
    {
        $names = [];
        $entries = [];
        foreach ($namesOrEntries as $item) {
            if ($item instanceof AttributeEntry) {
                $entries[] = $item;
                $names[] = ltrim($item->name, '\\');
            } else {
                $names[] = ltrim((string) $item, '\\');
            }
        }
        if ([] === $names) {
            return;
        }
        self::$classNames[$classLc] = $names;
        if ([] !== $entries) {
            self::$classEntries[$classLc] = $entries;
        }
    }

    /** @param list<string> $names */
    public static function recordMethod(string $classLc, string $methodLc, array $names): void
    {
        if ([] === $names) {
            return;
        }
        self::$methodNames[$classLc][$methodLc] = $names;
    }

    public static function ensureLinked(Context $context): void
    {
        ReflectionNative::registerDeclarations($context);
    }

    public static function implementLookupFunctions(Context $context): void
    {
        $fn = $context->module->getNamedFunction('phpc_attr_class_count');
        if (null !== $fn && $fn->countBasicBlocks() > 0) {
            return;
        }

        $classNames = self::$classNames;
        $classEntries = self::$classEntries;
        $methodNames = self::$methodNames;
        self::resetAccumulated();

        self::implementClassCount($context, $classNames);
        self::implementClassNameAt($context, $classNames);
        self::implementMethodCount($context, $methodNames);
        self::implementMethodNameAt($context, $methodNames);
        self::implementClassArgsHashtable($context, $classEntries);
        self::implementClassStringArg($context);
        $context->builder->clearInsertionPosition();
    }

    public static function resetAccumulated(): void
    {
        self::$classNames = [];
        self::$classEntries = [];
        self::$methodNames = [];
    }

    /** @param array<string, list<string>> $classNames */
    private static function implementClassCount(Context $context, array $classNames): void
    {
        $fn = self::defineFunction($context, 'phpc_attr_class_count', 'size_t', ['int8*']);
        if (null === $fn) {
            return;
        }
        $classLc = self::param($fn, 0);
        $exit = BasicBlockHelper::append($context, self::tag('attr_cc_exit'));
        $context->builder->positionAtEnd($exit);
        $context->builder->returnValue($context->constantFromInteger(0, 'size_t'));
        $next = $exit;
        foreach ($classNames as $classLcKey => $names) {
            $check = BasicBlockHelper::append($context, self::tag('attr_cc_chk'));
            $match = BasicBlockHelper::append($context, self::tag('attr_cc_match'));
            $context->builder->positionAtEnd($check);
            $eq = self::emitCstrEqualsLiteral($context, $classLc, $classLcKey);
            $context->builder->branchIf($eq, $match, $next);
            $context->builder->positionAtEnd($match);
            $context->builder->returnValue($context->constantFromInteger(count($names), 'size_t'));
            $next = $check;
        }
        $entry = $fn->getFirstBasicBlock();
        if (null !== $entry && $entry !== $next) {
            $context->builder->positionAtEnd($entry);
            $context->builder->branch($next);
        }
        $context->builder->clearInsertionPosition();
    }

    /** @param array<string, list<string>> $classNames */
    private static function implementClassNameAt(Context $context, array $classNames): void
    {
        $fn = self::defineFunction($context, 'phpc_attr_class_name_at', 'int8*', ['int8*', 'size_t']);
        if (null === $fn) {
            return;
        }
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $classLc = self::param($fn, 0);
        $idx = self::param($fn, 1);
        $exit = BasicBlockHelper::append($context, self::tag('attr_cna_exit'));
        $context->builder->positionAtEnd($exit);
        $context->builder->returnValue(
            $context->builder->pointerCast($context->constantFromString(''), $i8p)
        );
        $next = $exit;
        foreach ($classNames as $classLcKey => $names) {
            foreach ($names as $nameIdx => $name) {
                $check = BasicBlockHelper::append($context, self::tag('attr_cna_chk'));
                $match = BasicBlockHelper::append($context, self::tag('attr_cna_match'));
                $context->builder->positionAtEnd($check);
                $classEq = self::emitCstrEqualsLiteral($context, $classLc, $classLcKey);
                $idxEq = $context->builder->icmp(Builder::INT_EQ, $idx, $sizeT->constInt($nameIdx, false));
                $both = $context->builder->and($classEq, $idxEq);
                $context->builder->branchIf($both, $match, $next);
                $context->builder->positionAtEnd($match);
                $ptr = $context->builder->pointerCast($context->constantFromString($name), $i8p);
                $context->builder->returnValue($ptr);
                $next = $check;
            }
        }
        $entry = $fn->getFirstBasicBlock();
        if (null !== $entry && $entry !== $next) {
            $context->builder->positionAtEnd($entry);
            $context->builder->branch($next);
        }
        $context->builder->clearInsertionPosition();
    }

    /** @param array<string, array<string, list<string>>> $methodNames */
    private static function implementMethodCount(Context $context, array $methodNames): void
    {
        $fn = self::defineFunction($context, 'phpc_attr_method_count', 'size_t', ['int8*', 'int8*']);
        if (null === $fn) {
            return;
        }
        $sizeT = $context->getTypeFromString('size_t');
        $classLc = self::param($fn, 0);
        $methodLc = self::param($fn, 1);
        $exit = BasicBlockHelper::append($context, self::tag('attr_mc_exit'));
        $context->builder->positionAtEnd($exit);
        $context->builder->returnValue($context->constantFromInteger(0, 'size_t'));
        $next = $exit;
        foreach ($methodNames as $classLcKey => $methods) {
            foreach ($methods as $methodLcKey => $names) {
                $check = BasicBlockHelper::append($context, self::tag('attr_mc_chk'));
                $match = BasicBlockHelper::append($context, self::tag('attr_mc_match'));
                $context->builder->positionAtEnd($check);
                $classEq = self::emitCstrEqualsLiteral($context, $classLc, $classLcKey);
                $methodEq = self::emitCstrEqualsLiteral($context, $methodLc, $methodLcKey);
                $both = $context->builder->and($classEq, $methodEq);
                $context->builder->branchIf($both, $match, $next);
                $context->builder->positionAtEnd($match);
                $context->builder->returnValue($context->constantFromInteger(count($names), 'size_t'));
                $next = $check;
            }
        }
        $entry = $fn->getFirstBasicBlock();
        if (null !== $entry && $entry !== $next) {
            $context->builder->positionAtEnd($entry);
            $context->builder->branch($next);
        }
        $context->builder->clearInsertionPosition();
    }

    /** @param array<string, array<string, list<string>>> $methodNames */
    private static function implementMethodNameAt(Context $context, array $methodNames): void
    {
        $fn = self::defineFunction($context, 'phpc_attr_method_name_at', 'int8*', ['int8*', 'int8*', 'size_t']);
        if (null === $fn) {
            return;
        }
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $classLc = self::param($fn, 0);
        $methodLc = self::param($fn, 1);
        $idx = self::param($fn, 2);
        $exit = BasicBlockHelper::append($context, self::tag('attr_mna_exit'));
        $context->builder->positionAtEnd($exit);
        $context->builder->returnValue(
            $context->builder->pointerCast($context->constantFromString(''), $i8p)
        );
        $next = $exit;
        foreach ($methodNames as $classLcKey => $methods) {
            foreach ($methods as $methodLcKey => $names) {
                foreach ($names as $nameIdx => $name) {
                    $check = BasicBlockHelper::append($context, self::tag('attr_mna_chk'));
                    $match = BasicBlockHelper::append($context, self::tag('attr_mna_match'));
                    $context->builder->positionAtEnd($check);
                    $classEq = self::emitCstrEqualsLiteral($context, $classLc, $classLcKey);
                    $methodEq = self::emitCstrEqualsLiteral($context, $methodLc, $methodLcKey);
                    $idxEq = $context->builder->icmp(Builder::INT_EQ, $idx, $sizeT->constInt($nameIdx, false));
                    $both = $context->builder->and($classEq, $methodEq);
                    $all = $context->builder->and($both, $idxEq);
                    $context->builder->branchIf($all, $match, $next);
                    $context->builder->positionAtEnd($match);
                    $ptr = $context->builder->pointerCast($context->constantFromString($name), $i8p);
                    $context->builder->returnValue($ptr);
                    $next = $check;
                }
            }
        }
        $entry = $fn->getFirstBasicBlock();
        if (null !== $entry && $entry !== $next) {
            $context->builder->positionAtEnd($entry);
            $context->builder->branch($next);
        }
        $context->builder->clearInsertionPosition();
    }

    /** @param array<string, list<AttributeEntry>> $classEntries */
    private static function implementClassArgsHashtable(Context $context, array $classEntries): void
    {
        $fn = self::defineFunction($context, 'phpc_attr_class_args_hashtable', '__hashtable__*', ['int8*', 'size_t']);
        if (null === $fn) {
            return;
        }
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $sizeT = $context->getTypeFromString('size_t');
        $classLc = self::param($fn, 0);
        $attrIdx = self::param($fn, 1);
        $exit = BasicBlockHelper::append($context, self::tag('attr_args_exit'));
        $context->builder->positionAtEnd($exit);
        $voidp = $context->getTypeFromString('void*');
        $context->builder->returnValue(
            $context->builder->pointerCast($voidp->constNull(), $htPtr)
        );
        $next = $exit;
        foreach ($classEntries as $classLcKey => $entries) {
            foreach ($entries as $entryIdx => $entry) {
                if ([] === $entry->args) {
                    continue;
                }
                $check = BasicBlockHelper::append($context, self::tag('attr_args_chk'));
                $match = BasicBlockHelper::append($context, self::tag('attr_args_match'));
                $context->builder->positionAtEnd($check);
                $classEq = self::emitCstrEqualsLiteral($context, $classLc, $classLcKey);
                $idxEq = $context->builder->icmp(Builder::INT_EQ, $attrIdx, $sizeT->constInt($entryIdx, false));
                $both = $context->builder->and($classEq, $idxEq);
                $context->builder->branchIf($both, $match, $next);
                $context->builder->positionAtEnd($match);
                $built = self::emitBuildArgsHashtable($context, $entry->args);
                $context->builder->returnValue($built);
                $next = $check;
            }
        }
        $entry = $fn->getFirstBasicBlock();
        if (null !== $entry && $entry !== $next) {
            $context->builder->positionAtEnd($entry);
            $context->builder->branch($next);
        }
        $context->builder->clearInsertionPosition();
    }

    private static function implementClassStringArg(Context $context): void
    {
        $fn = self::defineFunction($context, 'phpc_attr_class_string_arg', 'int8*', ['int8*', 'size_t', 'size_t']);
        if (null === $fn) {
            return;
        }
        $i8p = $context->getTypeFromString('int8*');
        $context->builder->positionAtEnd($fn->getFirstBasicBlock());
        $context->builder->returnValue(
            $context->builder->pointerCast($context->constantFromString(''), $i8p)
        );
        $context->builder->clearInsertionPosition();
    }

    /**
     * @param list<array{name: ?string, value: mixed}> $args
     */
    private static function emitBuildArgsHashtable(Context $context, array $args): Value
    {
        $ht = HashTableHelper::alloc($context);
        $sizeT = $context->getTypeFromString('size_t');
        $count = count($args);
        if ($count > 0) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__grow'),
                $ht,
                $sizeT->constInt($count, false)
            );
        }
        foreach ($args as $ai => $spec) {
            $entryHt = HashTableHelper::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__hashtable__grow'),
                $entryHt,
                $sizeT->constInt(2, false)
            );
            self::emitWriteArgEntry($context, $entryHt, $spec);
            $entryVar = new Variable(
                $context,
                Variable::TYPE_HASHTABLE,
                Variable::KIND_VALUE,
                $entryHt
            );
            HashTableHelper::setAtIndex($context, $ht, $sizeT->constInt($ai, false), $entryVar);
        }

        return $ht;
    }

    /**
     * @param array{name: ?string, value: mixed} $spec
     */
    private static function emitWriteArgEntry(Context $context, Value $entryHt, array $spec): void
    {
        $nameKey = $context->builder->load($context->constantStringFromString('name'));
        $valueKey = $context->builder->load($context->constantStringFromString('value'));
        if (null !== $spec['name'] && '' !== $spec['name']) {
            $nameVal = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $context->builder->load($context->constantStringFromString((string) $spec['name']))
            );
            $nameVar = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $nameVal);
            HashTableHelper::setAtStringKey($context, $entryHt, $nameKey, $nameVar);
        }
        $value = $spec['value'];
        if (null === $value) {
            return;
        }
        if (is_bool($value)) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyBool'),
                $entryHt,
                $valueKey,
                $context->getTypeFromString('int32')->constInt($value ? 1 : 0, false)
            );

            return;
        }
        if (is_int($value)) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyLong'),
                $entryHt,
                $valueKey,
                $context->getTypeFromString('int64')->constInt($value, false)
            );

            return;
        }
        if (is_float($value)) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyDouble'),
                $entryHt,
                $valueKey,
                $context->constantFromFloat($value, 'double')
            );

            return;
        }
        if (is_string($value)) {
            $strVal = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $context->builder->load($context->constantStringFromString($value))
            );
            $strVar = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $strVal);
            HashTableHelper::setAtStringKey($context, $entryHt, $valueKey, $strVar);
        }
    }

    private static function emitCstrEqualsLiteral(Context $context, Value $runtimeCstr, string $literal): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $lit = $context->builder->pointerCast($context->constantFromString($literal), $i8p);
        $cmp = $context->builder->call($context->lookupFunction('strcasecmp'), $runtimeCstr, $lit);

        return $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
    }

    /**
     * @param list<string> $paramTypes
     */
    private static function defineFunction(Context $context, string $name, string $retType, array $paramTypes): ?Value\Function_
    {
        $existing = $context->module->getNamedFunction($name);
        if (null !== $existing && $existing->countBasicBlocks() > 0) {
            return null;
        }
        if (null === $existing) {
            $params = array_map(
                static fn (string $t) => $context->getTypeFromString($t),
                $paramTypes
            );
            $ft = $context->context->functionType(
                $context->getTypeFromString($retType),
                false,
                ...$params
            );
            $existing = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $existing);
        }
        $block = $existing->appendBasicBlock('entry');
        $context->builder->positionAtEnd($block);

        return $existing;
    }

    private static function param(Value\Function_ $fn, int $index): Value
    {
        $arg = $fn->getParam($index);
        if (null === $arg) {
            throw new \LogicException('Missing function parameter '.$index);
        }

        return $arg;
    }

    private static function tag(string $prefix): string
    {
        return $prefix.'_'.(string) (++self::$seq);
    }
}
