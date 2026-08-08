<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

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
     *
     * @param ?string $callerClassLc active class scope for Class::CONST visibility (#29130)
     */
    public static function constantLookup(
        Context $ctx,
        string $name,
        ?string $callerClassLc = null
    ): ?Variable {
        if (str_contains($name, '::')) {
            return self::lookupClassConstant($ctx, $name, $callerClassLc);
        }

        return self::globalConstantLookup($ctx, $name);
    }

    /**
     * Global-only constant fetch — zend_get_constant_ptr semantics (#23604).
     * Never resolves Class::CONST (that is constant() / ReflectionClassConstant).
     */
    public static function globalConstantLookup(Context $ctx, string $name): ?Variable
    {
        if (str_contains($name, '::')) {
            return null;
        }

        return $ctx->constantFetchBuiltin(VmReflection::normalizeGlobalIntrospectionName($name));
    }

    /**
     * defined() lookup — existence without materializing value (#4972, basic_functions.c).
     *
     * @param ?string $callerClassLc active class scope for Class::CONST visibility (#29130)
     */
    public static function constantDefined(
        Context $ctx,
        string $name,
        ?string $callerClassLc = null
    ): bool {
        if (str_contains($name, '::')) {
            return self::isClassConstantDefined($ctx, $name, $callerClassLc);
        }

        return self::globalConstantDefined($ctx, $name);
    }

    /**
     * Global-only defined() — ReflectionConstant::__construct (#23604, php_reflection.c).
     * Class::CONST names are not global constants (use ReflectionClassConstant).
     */
    public static function globalConstantDefined(Context $ctx, string $name): bool
    {
        if (str_contains($name, '::')) {
            return false;
        }

        return $ctx->constantDefinedBuiltin(VmReflection::normalizeGlobalIntrospectionName($name));
    }

    /**
     * @see Zend zif_defined — class constant exists and is visible from caller scope (#29130)
     */
    private static function isClassConstantDefined(
        Context $ctx,
        string $qualifiedName,
        ?string $callerClassLc
    ): bool {
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
        // Exact casing key (#25910) — do not strtolower; that missed visibility and treated private as public (#29130).
        $decl = VmReflection::findClassConstantDecl($classEntry, $constName, $ctx);
        if (null === $decl) {
            return false;
        }
        $declaring = $decl['declaring'];
        $constKey = $decl['constLc'];
        $vis = $declaring->constVisibility[$constKey] ?? CfgFunc::FLAG_PUBLIC;
        $declaringLc = strtolower(ltrim($declaring->name, '\\'));
        try {
            ClassConstVisibility::assertAccessible(
                $vis,
                $callerClassLc,
                $declaringLc,
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
     * @see Zend zif_constant — zend_fetch_class + class constant table + visibility (#29130)
     */
    private static function lookupClassConstant(
        Context $ctx,
        string $qualifiedName,
        ?string $callerClassLc
    ): ?Variable {
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
        // Walk parentLc so subclass::CONST (e.g. RecursiveArrayIterator::ARRAY_AS_PROPS) resolves (#22348).
        // Visibility keyed by ClassConstName::key (exact case), not strtolower (#29130 / #25910).
        $decl = VmReflection::findClassConstantDecl($classEntry, $constName, $ctx);
        if (null === $decl) {
            return null;
        }
        $declaring = $decl['declaring'];
        $constKey = $decl['constLc'];
        $vis = $declaring->constVisibility[$constKey] ?? CfgFunc::FLAG_PUBLIC;
        $declaringLc = strtolower(ltrim($declaring->name, '\\'));
        try {
            ClassConstVisibility::assertAccessible(
                $vis,
                $callerClassLc,
                $declaringLc,
                $classEntry->name,
                $constName,
                static fn (string $callerLc, string $ancestorLc): bool => isset($ctx->classes[$callerLc])
                    && self::isClassSameOrSubclassOf($ctx, $callerLc, $ancestorLc)
            );
        } catch (\LogicException $e) {
            throw new \Error($e->getMessage(), 0, $e);
        }
        $result = new Variable();
        if ($declaring->isEnum && null !== $declaring->backedType) {
            \PHPCompiler\VM\EnumSupport::ensureBackedEnumValuesUnique($declaring);
        }
        if (EnumCaseSupport::tryMaterializeEnumCaseConstantFetch($declaring, $constKey, $result)) {
            $result->copyFrom(EnumCaseSupport::materializeConstantValue($ctx, $result));

            return $result;
        }
        $result->copyFrom(
            EnumCaseSupport::materializeConstantValue($ctx, $declaring->constants[$constKey])
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
                'crypt_sha256',
                'crypt_sha512',
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
        foreach (self::categorizedCoreConstantEntries($ctx) as $name => $value) {
            $copy = new Variable();
            $copy->copyFrom($value);
            $core->add($name, $copy);
        }
        $result->add('Core', self::wrapArray($core));

        foreach (ExtensionConstantGroups::groups() as $extension => $registered) {
            if (!ExtensionConstantGroups::shouldMaterializeExtensionBucket($extension)) {
                continue;
            }
            $bucket = self::extensionConstantBucket($registered, $ctx);
            if ($bucket->getNumElements() > 0) {
                $result->add($extension, self::wrapArray($bucket));
            }
        }

        if ([] !== $ctx->constants) {
            $user = new HashTable();
            foreach ($ctx->constants as $name => $value) {
                if (ExtensionConstantGroups::isExtensionConstantName($name)) {
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
     * @return array<string, Variable>
     */
    private static function categorizedCoreConstantEntries(Context $ctx): array
    {
        $entries = VmPhpCoreConstants::categorizedCoreEntries();
        foreach (['true', 'false', 'null'] as $fetchName) {
            $value = $ctx->constantFetch($fetchName);
            if (null === $value) {
                continue;
            }
            $outName = match ($fetchName) {
                'true' => 'TRUE',
                'false' => 'FALSE',
                'null' => 'NULL',
                default => strtoupper($fetchName),
            };
            $entries[$outName] = $value;
        }
        foreach (Context::errorReportingConstantFetchNames() as $fetchName) {
            $value = $ctx->constantFetch($fetchName);
            if (null === $value) {
                continue;
            }
            $entries[strtoupper($fetchName)] = $value;
        }
        foreach (ExtensionConstantGroups::coreBucketNames() as $name) {
            if (!isset($ctx->constants[$name])) {
                continue;
            }
            $entries[$name] = $ctx->constants[$name];
        }
        foreach (['STDIN', 'STDOUT', 'STDERR'] as $name) {
            if (!isset($ctx->constants[$name])) {
                continue;
            }
            $entries[$name] = $ctx->constants[$name];
        }

        return $entries;
    }

    /**
     * @param array<string, int> $registered
     */
    private static function extensionConstantBucket(array $registered, Context $ctx): HashTable
    {
        $bucket = new HashTable();
        foreach ($registered as $name => $fallback) {
            $value = $ctx->constants[$name] ?? null;
            if (null === $value && \is_int($fallback)) {
                $var = new Variable(Variable::TYPE_INTEGER);
                $var->int($fallback);
                $value = $var;
            } elseif (null === $value && \is_float($fallback)) {
                $var = new Variable(Variable::TYPE_FLOAT);
                $var->float($fallback);
                $value = $var;
            } elseif (null === $value && \is_string($fallback)) {
                $var = new Variable(Variable::TYPE_STRING);
                $var->string($fallback);
                $value = $var;
            }
            if (null === $value) {
                continue;
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
