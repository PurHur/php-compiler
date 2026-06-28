<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\ext\filter\FilterConstants;
use PHPCompiler\ClassConstVisibility;
use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * VM helpers for get_defined_constants() (php-src: ext/standard/basic_functions.c).
 */
final class VmConstants
{
    /** @var list<string>|null */
    private static ?array $coreFetchNames = null;

    /**
     * constant() lookup — user/core constants and Class::CONST (#5926, basic_functions.c).
     */
    public static function constantLookup(Context $ctx, string $name): ?Variable
    {
        if (str_contains($name, '::')) {
            return self::lookupClassConstant($ctx, $name);
        }

        return $ctx->constantFetchBuiltin(VmReflection::normalizeGlobalIntrospectionName($name));
    }

    /**
     * defined() lookup — existence without materializing value (#4972, basic_functions.c).
     */
    public static function constantDefined(Context $ctx, string $name): bool
    {
        if (str_contains($name, '::')) {
            return self::isClassConstantDefined($ctx, $name);
        }

        return $ctx->constantDefinedBuiltin(VmReflection::normalizeGlobalIntrospectionName($name));
    }

    /**
     * @see Zend zif_constant — zend_fetch_class + class constant table
     */
    private static function isClassConstantDefined(Context $ctx, string $qualifiedName): bool
    {
        $pos = strrpos($qualifiedName, '::');
        if (false === $pos) {
            return false;
        }
        $className = substr($qualifiedName, 0, $pos);
        $constName = substr($qualifiedName, $pos + 2);
        if ('' === $className || '' === $constName) {
            return false;
        }
        $classLc = strtolower(ltrim($className, '\\'));
        if (!isset($ctx->classes[$classLc])) {
            $ctx->autoloadClass($className);
        }
        if (!isset($ctx->classes[$classLc])) {
            return false;
        }
        $classEntry = $ctx->classes[$classLc];
        if ($classEntry->isTrait) {
            return false;
        }
        $constKey = VmReflection::findClassConstantKey($classEntry, $constName, $ctx);
        if (null === $constKey) {
            return false;
        }
        $constLc = strtolower($constName);
        $vis = $classEntry->constVisibility[$constLc] ?? CfgFunc::FLAG_PUBLIC;
        try {
            ClassConstVisibility::assertAccessible(
                $vis,
                null,
                $classLc,
                $classEntry->name,
                $constName,
                static fn (string $callerLc, string $ancestorLc): bool => isset($ctx->classes[$callerLc])
                    && self::isClassSameOrSubclassOf($ctx, $callerLc, $ancestorLc)
            );
        } catch (\LogicException $e) {
            return false;
        }

        return true;
    }

    /**
     * @see Zend zif_constant — zend_fetch_class + class constant table
     */
    private static function lookupClassConstant(Context $ctx, string $qualifiedName): ?Variable
    {
        $pos = strrpos($qualifiedName, '::');
        if (false === $pos) {
            return null;
        }
        $className = substr($qualifiedName, 0, $pos);
        $constName = substr($qualifiedName, $pos + 2);
        if ('' === $className || '' === $constName) {
            return null;
        }
        $classLc = strtolower(ltrim($className, '\\'));
        if (!isset($ctx->classes[$classLc])) {
            $ctx->autoloadClass($className);
        }
        if (!isset($ctx->classes[$classLc])) {
            return null;
        }
        $classEntry = $ctx->classes[$classLc];
        if ($classEntry->isTrait) {
            throw new \Error(
                "Cannot access trait constant {$classEntry->name}::{$constName} directly"
            );
        }
        $constLc = strtolower($constName);
        $constKey = VmReflection::findClassConstantKey($classEntry, $constName, $ctx);
        if (null === $constKey) {
            return null;
        }
        $vis = $classEntry->constVisibility[$constLc] ?? CfgFunc::FLAG_PUBLIC;
        try {
            ClassConstVisibility::assertAccessible(
                $vis,
                null,
                $classLc,
                $classEntry->name,
                $constName,
                static fn (string $callerLc, string $ancestorLc): bool => isset($ctx->classes[$callerLc])
                    && self::isClassSameOrSubclassOf($ctx, $callerLc, $ancestorLc)
            );
        } catch (\LogicException $e) {
            throw new \Error($e->getMessage(), 0, $e);
        }
        $result = new Variable();
        if ($classEntry->isEnum && null !== $classEntry->backedType) {
            \PHPCompiler\VM\EnumSupport::ensureBackedEnumValuesUnique($classEntry);
        }
        if (EnumCaseSupport::tryMaterializeEnumCaseConstantFetch($classEntry, $constKey, $result)) {
            $result->copyFrom(EnumCaseSupport::materializeConstantValue($ctx, $result));

            return $result;
        }
        $result->copyFrom(
            EnumCaseSupport::materializeConstantValue($ctx, $classEntry->constants[$constKey])
        );

        return $result;
    }

    private static function isClassSameOrSubclassOf(Context $ctx, string $classLc, string $ancestorLc): bool
    {
        $current = $classLc;
        while (isset($ctx->classes[$current])) {
            if ($current === $ancestorLc) {
                return true;
            }
            $parent = $ctx->classes[$current]->parentLc;
            if (null === $parent) {
                return false;
            }
            $current = $parent;
        }

        return false;
    }

    /**
     * Names resolved by VM\Context::constantFetch() (Core category).
     *
     * @return list<string>
     */
    private static function coreFetchNames(): array
    {
        if (null !== self::$coreFetchNames) {
            return self::$coreFetchNames;
        }

        self::$coreFetchNames = \array_merge(
            [
                'true',
                'false',
                'password_bcrypt',
                'password_default',
                'password_argon2i',
                'password_argon2id',
                'crypt_std_des',
                'crypt_ext_des',
                'crypt_md5',
                'crypt_blowfish',
            ],
            array_keys(DateConstants::CORE_STRING_BY_NAME),
            Context::errorReportingConstantFetchNames(),
            StdlibConstants::CORE_FETCH_NAMES,
        );

        return self::$coreFetchNames;
    }

    public static function getDefinedConstants(Context $ctx, bool $categorize = false): HashTable
    {
        if ($categorize) {
            return self::buildCategorized($ctx);
        }

        return self::buildFlat($ctx);
    }

    /**
     * PHP 8.4+ category filter — flat map for one extension category (#12947, basic_functions.c).
     */
    public static function getDefinedConstantsForCategory(Context $ctx, string $category): HashTable
    {
        $categorized = self::buildCategorized($ctx);
        foreach ($categorized->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_STRING !== $key->type) {
                continue;
            }
            if (0 !== strcasecmp($key->toString(), $category)) {
                continue;
            }
            $resolved = $valueVar->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $resolved->type) {
                return new HashTable();
            }
            $out = new HashTable();
            foreach ($resolved->toArray()->iterateKeyed(true) as [$constKeyVar, $constVar]) {
                $copy = new Variable();
                $copy->copyFrom($constVar);
                $constKey = $constKeyVar->resolveIndirect();
                if (Variable::TYPE_STRING === $constKey->type) {
                    $out->add($constKey->toString(), $copy);
                } elseif (Variable::TYPE_INTEGER === $constKey->type) {
                    $out->addIndex($constKey->toInt(), $copy);
                }
            }

            return $out;
        }

        return new HashTable();
    }

    private static function buildFlat(Context $ctx): HashTable
    {
        $result = new HashTable();
        foreach (self::coreConstantEntries($ctx) as $name => $value) {
            $copy = new Variable();
            $copy->copyFrom($value);
            $result->add($name, $copy);
        }
        foreach ($ctx->constants as $name => $value) {
            $copy = new Variable();
            $copy->copyFrom($value);
            $result->add($name, $copy);
        }

        return $result;
    }

    private static function buildCategorized(Context $ctx): HashTable
    {
        $result = new HashTable();
        $core = new HashTable();
        foreach (self::coreConstantEntries($ctx, true) as $name => $value) {
            $copy = new Variable();
            $copy->copyFrom($value);
            $core->add($name, $copy);
        }
        $result->add('Core', self::wrapArray($core));

        $filter = self::extensionConstantBucket(FilterConstants::REGISTERED, $ctx);
        if ($filter->getNumElements() > 0) {
            $result->add('filter', self::wrapArray($filter));
        }

        if ([] !== $ctx->constants) {
            $user = new HashTable();
            foreach ($ctx->constants as $name => $value) {
                if (FilterConstants::isRegisteredName($name)) {
                    continue;
                }
                $copy = new Variable();
                $copy->copyFrom($value);
                $user->add($name, $copy);
            }
            if ($user->getNumElements() > 0) {
                $result->add('user', self::wrapArray($user));
            }
        }

        return $result;
    }

    /**
     * @param array<string, int> $registered
     */
    private static function extensionConstantBucket(array $registered, Context $ctx): HashTable
    {
        $bucket = new HashTable();
        foreach ($registered as $name => $fallback) {
            $value = $ctx->constants[$name] ?? FilterConstants::variableForName($name);
            if (null === $value) {
                $var = new Variable(Variable::TYPE_INTEGER);
                $var->int($fallback);
                $value = $var;
            }
            $copy = new Variable();
            $copy->copyFrom($value);
            $bucket->add($name, $copy);
        }

        return $bucket;
    }

    /**
     * @return array<string, Variable>
     */
    private static function coreConstantEntries(Context $ctx, bool $categorized = false): array
    {
        $entries = [];
        foreach (self::coreFetchNames() as $fetchName) {
            $value = $ctx->constantFetch($fetchName);
            if (null === $value) {
                continue;
            }
            $outName = self::coreOutputName($fetchName, $categorized);
            if (null === $outName) {
                continue;
            }
            $entries[$outName] = $value;
        }
        foreach (VmPhpCoreConstants::definedCoreEntries() as $coreName => $value) {
            $entries[$coreName] = $value;
        }

        return $entries;
    }

    private static function coreOutputName(string $fetchName, bool $categorized): ?string
    {
        return match ($fetchName) {
            'true' => 'TRUE',
            'false' => 'FALSE',
            default => strtoupper($fetchName),
        };
    }

    private static function wrapArray(HashTable $table): Variable
    {
        $var = new Variable(Variable::TYPE_ARRAY);
        $var->array($table);

        return $var;
    }
}
