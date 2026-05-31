<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * VM helpers for get_defined_constants() (php-src: ext/standard/basic_functions.c).
 */
final class VmConstants
{
    /** Names resolved by VM\Context::constantFetch() (Core category). */
    private const CORE_FETCH_NAMES = [
        'true',
        'false',
        'password_bcrypt',
        'password_default',
        'filter_validate_int',
        'filter_validate_email',
        'input_get',
        'input_post',
        ...Context::errorReportingConstantFetchNames(),
        ...StdlibConstants::CORE_FETCH_NAMES,
    ];

    public static function getDefinedConstants(Context $ctx, bool $categorize = false): HashTable
    {
        if ($categorize) {
            return self::buildCategorized($ctx);
        }

        return self::buildFlat($ctx);
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

        if ([] !== $ctx->constants) {
            $user = new HashTable();
            foreach ($ctx->constants as $name => $value) {
                $copy = new Variable();
                $copy->copyFrom($value);
                $user->add($name, $copy);
            }
            $result->add('user', self::wrapArray($user));
        }

        return $result;
    }

    /**
     * @return array<string, Variable>
     */
    private static function coreConstantEntries(Context $ctx, bool $categorized = false): array
    {
        $entries = [];
        foreach (self::CORE_FETCH_NAMES as $fetchName) {
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
