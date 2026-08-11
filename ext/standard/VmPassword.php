<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * VM password_hash() / password_verify() / crypt() — bcrypt via VmPasswordPure, Argon2 via VmPasswordNative (#4794, #6906, #14182).
 * password_get_info() / password_needs_rehash() — native PHP (issue #6503).
 */
final class VmPassword
{
    public const PASSWORD_BCRYPT = 1;

    /** php-src REGISTER_STRING_CONSTANT("PASSWORD_DEFAULT", "2y", ...) */
    public const PASSWORD_DEFAULT = '2y';

    /** php-src ext/standard/password.c — registered when Argon2 is available. */
    public const PASSWORD_ARGON2I = 2;

    public const PASSWORD_ARGON2ID = 3;

    public const PASSWORD_ALGO_INVALID_MSG = 'password_hash(): Argument #2 ($algo) must be a valid password hashing algorithm';

    /** @var array<string, int> php-src password_algos() names accepted by password_hash() (ext/standard/password.c). */
    private const STRING_ALGOS = [
        '2y' => self::PASSWORD_BCRYPT,
        'argon2i' => self::PASSWORD_ARGON2I,
        'argon2id' => self::PASSWORD_ARGON2ID,
    ];

    /**
     * php-src ext/standard/password.c — PHP 8.4 raised the default from 10 to 12.
     */
    public static function bcryptDefaultCost(): int
    {
        return version_compare(\PHPCompiler\CompilerVersion::languageProfileVersion(), '8.4.0', '>=') ? 12 : 10;
    }

    /** crypt() salt generation flags (ext/standard/crypt.c — all CRYPT_* are bitmask 1). */
    public const CRYPT_STD_DES = 1;

    public const CRYPT_EXT_DES = 1;

    public const CRYPT_MD5 = 1;

    public const CRYPT_BLOWFISH = 1;

    public const CRYPT_SHA256 = 1;

    public const CRYPT_SHA512 = 1;

    public static function hash(string $password, int $algo, array $options = [])
    {
        return VmPasswordNative::passwordHash($password, $algo, $options);
    }

    /**
     * Resolve password_hash() $algo — int, string, or null (php-src php_password_algo_find, issue #5039, #18155).
     *
     * @throws \TypeError when operand is not string|int|null
     * @throws \ValueError when algo name/id is not a supported password hashing algorithm
     */
    public static function resolveAlgo(Variable $var, string $function, int $argIndex, string $paramName): int
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return self::PASSWORD_BCRYPT;
        }
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type string|int, %s given',
                $function,
                $argIndex + 1,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            $algo = $var->toInt();
            if (self::algoSupported($algo)) {
                return $algo;
            }

            throw new \ValueError(self::PASSWORD_ALGO_INVALID_MSG);
        }
        if (Variable::TYPE_STRING === $var->type) {
            $name = $var->toString();
            if (isset(self::STRING_ALGOS[$name])) {
                return self::STRING_ALGOS[$name];
            }

            throw new \ValueError(self::PASSWORD_ALGO_INVALID_MSG);
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type string|int, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            self::algoTypeLabel($var)
        ));
    }

    public static function verify(string $password, string $hash): bool
    {
        return VmPasswordNative::passwordVerify($password, $hash);
    }

    /** crypt() — host crypt() via VmPasswordPure (#3771, #4794, #14182). */
    public static function crypt(string $password, string $salt): string
    {
        return VmPasswordNative::crypt($password, $salt);
    }

    /**
     * password_get_info() — parse bcrypt/argon prefixes (ext/standard/password.c, #3649, #6503).
     *
     * @return array<string, mixed>
     */
    public static function getInfo(string $hash): array
    {
        $ident = self::extractIdent($hash);
        if (null === $ident) {
            return self::passwordInfoUnknown();
        }
        if ('2y' === $ident && self::bcryptValid($hash)) {
            return self::passwordInfoBcrypt($hash);
        }
        if ('argon2i' === $ident && str_starts_with($hash, '$argon2i$')) {
            return self::passwordInfoArgon2(substr($hash, 9), 'argon2i');
        }
        if ('argon2id' === $ident && str_starts_with($hash, '$argon2id$')) {
            return self::passwordInfoArgon2(substr($hash, 10), 'argon2id');
        }

        return self::passwordInfoUnknown();
    }

    /** password_needs_rehash() — bcrypt/argon option compare (ext/standard/password.c, #3279, #6503, #4149). */
    public static function needsRehash(string $hash, int $algo, array $options = []): bool
    {
        if (!self::algoSupported($algo)) {
            return false;
        }
        $info = self::getInfo($hash);
        $hashAlgo = $info['algo'];
        if (self::PASSWORD_BCRYPT === $algo) {
            if ('2y' !== $hashAlgo) {
                return true;
            }
            $newCost = 0;
            if (isset($options['cost']) && \is_int($options['cost'])) {
                $newCost = $options['cost'];
            }
            if ($newCost <= 0) {
                $newCost = self::bcryptDefaultCost();
            }

            return self::bcryptCostFromHash($hash) !== $newCost;
        }
        if (self::PASSWORD_ARGON2I === $algo) {
            if ('argon2i' !== $hashAlgo) {
                return true;
            }

            return self::argon2OptionsDiffer($info['options'], $options);
        }
        if (self::PASSWORD_ARGON2ID === $algo) {
            if ('argon2id' !== $hashAlgo) {
                return true;
            }

            return self::argon2OptionsDiffer($info['options'], $options);
        }

        return true;
    }

    private static function algoSupported(int $algo): bool
    {
        if (self::PASSWORD_BCRYPT === $algo) {
            return true;
        }
        if (!VmPasswordNative::argon2Available()) {
            return false;
        }

        return self::PASSWORD_ARGON2I === $algo || self::PASSWORD_ARGON2ID === $algo;
    }

    /** @param array<string, mixed> $hashOptions */
    /** @param array<string, mixed> $requested */
    private static function argon2OptionsDiffer(array $hashOptions, array $requested): bool
    {
        $memoryCost = 65536;
        $timeCost = 4;
        $threads = 1;
        if (isset($requested['memory_cost']) && \is_int($requested['memory_cost'])) {
            $memoryCost = $requested['memory_cost'];
        }
        if (isset($requested['time_cost']) && \is_int($requested['time_cost'])) {
            $timeCost = $requested['time_cost'];
        }
        if (isset($requested['threads']) && \is_int($requested['threads'])) {
            $threads = $requested['threads'];
        }

        return ($hashOptions['memory_cost'] ?? 65536) !== $memoryCost
            || ($hashOptions['time_cost'] ?? 4) !== $timeCost
            || ($hashOptions['threads'] ?? 1) !== $threads;
    }

    private static function algoTypeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $var->toObject()->class->name,
            Variable::TYPE_RESOURCE => 'resource',
            default => 'mixed',
        };
    }

    private static function bcryptValid(string $hash): bool
    {
        return 60 === \strlen($hash) && '$' === $hash[0] && '2' === $hash[1] && 'y' === $hash[2];
    }

    private static function extractIdent(string $hash): ?string
    {
        if (\strlen($hash) < 3 || '$' !== $hash[0]) {
            return null;
        }
        $end = strpos($hash, '$', 1);
        if (false === $end) {
            return null;
        }

        return substr($hash, 1, $end - 1);
    }

    /** @return array<string, mixed> */
    private static function passwordInfoUnknown(): array
    {
        return [
            'algo' => null,
            'algoName' => 'unknown',
            'options' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function passwordInfoBcrypt(string $hash): array
    {
        $cost = self::bcryptDefaultCost();
        if (1 === sscanf($hash, '$2y$%d$', $parsed)) {
            $cost = $parsed;
        }

        return [
            'algo' => '2y',
            'algoName' => 'bcrypt',
            'options' => ['cost' => $cost],
        ];
    }

    /** @return array<string, mixed> */
    private static function passwordInfoArgon2(string $params, string $name): array
    {
        $v = 0;
        $memoryCost = 65536;
        $timeCost = 4;
        $threads = 1;
        sscanf($params, 'v=%d$m=%d,t=%d,p=%d', $v, $memoryCost, $timeCost, $threads);

        return [
            'algo' => $name,
            'algoName' => $name,
            'options' => [
                'memory_cost' => $memoryCost,
                'time_cost' => $timeCost,
                'threads' => $threads,
            ],
        ];
    }

    private static function bcryptCostFromHash(string $hash): int
    {
        if (!self::bcryptValid($hash)) {
            return self::bcryptDefaultCost();
        }
        if (\strlen($hash) >= 7 && str_starts_with($hash, '$2y$')) {
            $cost = (int) substr($hash, 4, 2);
            if ($cost < 4) {
                return self::bcryptDefaultCost();
            }

            return $cost;
        }

        return self::bcryptDefaultCost();
    }

    /** password_algos() — native list (ext/standard/password.c, issue #6195, #4794). */
    public static function algos(): HashTable
    {
        $ht = new HashTable();
        foreach (VmPasswordNative::passwordAlgos() as $algo) {
            $var = new Variable();
            $var->string($algo);
            $ht->append($var);
        }

        return $ht;
    }

    /** @param array<string, mixed> $info */
    public static function infoToHashTable(array $info): HashTable
    {
        $ht = new HashTable();
        foreach ($info as $key => $value) {
            $slot = new Variable();
            if (\is_array($value)) {
                $slot->array(self::infoToHashTable($value));
            } elseif (null === $value) {
                $slot->null();
            } elseif (\is_int($value)) {
                $slot->int($value);
            } elseif (\is_string($value)) {
                $slot->string($value);
            } else {
                throw new \LogicException('password_get_info() returned unexpected value type');
            }
            $ht->add((string) $key, $slot);
        }

        return $ht;
    }
}
