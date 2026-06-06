<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * VM password_hash() / password_verify() / crypt() — delegates to host PHP (issue #172, #3771).
 * password_get_info() / password_needs_rehash() — native PHP (issue #6503, mirrors password_crypto.c).
 */
final class VmPassword
{
    public const PASSWORD_BCRYPT = 1;

    public const PASSWORD_DEFAULT = 1;

    private const BCRYPT_DEFAULT_COST = 10;

    /** crypt() salt generation flags (ext/standard/password.c — CRYPT_*). */
    public const CRYPT_STD_DES = 1;

    public const CRYPT_EXT_DES = 2;

    public const CRYPT_MD5 = 3;

    public const CRYPT_BLOWFISH = 4;

    public static function hash(string $password, int $algo, array $options = []) {
        if ($algo !== self::PASSWORD_BCRYPT && $algo !== self::PASSWORD_DEFAULT) {
            return false;
        }

        return \password_hash($password, \PASSWORD_BCRYPT, $options);
    }

    public static function verify(string $password, string $hash): bool
    {
        return \password_verify($password, $hash);
    }

    /**
     * crypt() — delegate to host PHP php_crypt() for Zend parity (issue #3771).
     */
    public static function crypt(string $password, string $salt): string
    {
        return \crypt($password, $salt);
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

    /** password_needs_rehash() — bcrypt cost compare (ext/standard/password.c, #3279, #6503). */
    public static function needsRehash(string $hash, int $algo, array $options = []): bool
    {
        if (!self::algoSupported($algo)) {
            return false;
        }
        if (!self::hashIdentIsBcrypt($hash)) {
            return true;
        }
        $newCost = 0;
        if (isset($options['cost']) && \is_int($options['cost'])) {
            $newCost = $options['cost'];
        }
        if ($newCost <= 0) {
            $newCost = self::BCRYPT_DEFAULT_COST;
        }

        return self::bcryptCostFromHash($hash) !== $newCost;
    }

    private static function algoSupported(int $algo): bool
    {
        return self::PASSWORD_BCRYPT === $algo || self::PASSWORD_DEFAULT === $algo;
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
        $cost = self::BCRYPT_DEFAULT_COST;
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

    private static function hashIdentIsBcrypt(string $hash): bool
    {
        $ident = self::extractIdent($hash);

        return null !== $ident && '2y' === $ident && self::bcryptValid($hash);
    }

    private static function bcryptCostFromHash(string $hash): int
    {
        if (!self::bcryptValid($hash)) {
            return self::BCRYPT_DEFAULT_COST;
        }
        if (\strlen($hash) >= 7 && str_starts_with($hash, '$2y$')) {
            $cost = (int) substr($hash, 4, 2);
            if ($cost < 4) {
                return self::BCRYPT_DEFAULT_COST;
            }

            return $cost;
        }

        return self::BCRYPT_DEFAULT_COST;
    }

    /** password_algos() — host PHP for Zend parity (ext/standard/password.c, issue #6195). */
    public static function algos(): HashTable
    {
        $ht = new HashTable();
        foreach (\password_algos() as $algo) {
            if (!\is_string($algo)) {
                throw new \LogicException('password_algos() returned unexpected value type');
            }
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
