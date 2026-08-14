<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\standard\VmHashNative;

/**
 * Incremental hash context state for compiled JIT/AOT (#3357, #23585, php-in-PHP).
 *
 * php-src: ext/hash/hash.c
 */
final class HashContextJitHelper
{
    /**
     * @var array<int, array{
     *     algo: string,
     *     algoId: int,
     *     ctx: array<string, mixed>,
     *     buf: string,
     *     finalized: bool,
     *     flags: int,
     *     hmacKey: ?string,
     *     key: string
     * }>
     */
    private static array $state = [];

    private static int $nextId = 1;

    public static function init(string $algo, int $flags = 0, string $key = ''): int
    {
        $algoId = VmHashNative::resolveAlgoId($algo);
        if (0 === $algoId) {
            throw new \ValueError('hash_init(): Argument #1 ($algo) must be a valid hashing algorithm');
        }
        $hmac = 0 !== ($flags & VmHashContext::HASH_HMAC);
        if ($hmac) {
            if (!VmHashNative::isCryptographicAlgoId($algoId)) {
                throw new \ValueError(
                    'hash_init(): Argument #1 ($algo) must be a cryptographic hashing algorithm if HMAC is requested'
                );
            }
            if ('' === $key) {
                // NestedJIT-safe: do not call VmString. Wording SSOT: VmString::zendArgumentMustNotBeEmptySuffix (#30625).
                throw new \ValueError(
                    'hash_init(): Argument #3 ($key) '.self::zendEmptyArgSuffix().' when HMAC is requested'
                );
            }
        }
        $id = self::$nextId++;
        if ($hmac) {
            $prepared = VmHashNative::incrementalHmacCreate($algoId, $key);
            self::$state[$id] = [
                'algo' => $algo,
                'algoId' => $algoId,
                'ctx' => $prepared['ctx'],
                'buf' => '',
                'finalized' => false,
                'flags' => $flags,
                'hmacKey' => $prepared['hmacKey'],
                'key' => $key,
            ];
        } else {
            self::$state[$id] = [
                'algo' => $algo,
                'algoId' => $algoId,
                'ctx' => VmHashNative::incrementalCreate($algoId),
                'buf' => '',
                'finalized' => false,
                'flags' => $flags,
                'hmacKey' => null,
                'key' => $key,
            ];
        }

        return $id;
    }

    /**
     * NestedJIT-safe copy of {@see \PHPCompiler\ext\standard\VmString::zendArgumentMustNotBeEmptySuffix} (#30625).
     */
    private static function zendEmptyArgSuffix(): string
    {
        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === $raw) {
            return 'cannot be empty';
        }
        $raw = trim($raw);
        if (isset($raw[0], $raw[1], $raw[2]) && '8' === $raw[0] && '.' === $raw[1] && $raw[2] >= '4') {
            return 'must not be empty';
        }

        return 'cannot be empty';
    }

    public static function update(int $id, string $data): int
    {
        $entry = self::requireLive($id);
        VmHashNative::incrementalUpdate($entry['algoId'], $entry['ctx'], $data);
        $entry['buf'] = $entry['buf'].$data;
        self::$state[$id] = $entry;

        return 1;
    }

    public static function algoName(int $id): string
    {
        return self::requireLive($id)['algo'];
    }

    public static function dataString(int $id): string
    {
        return self::requireLive($id)['buf'];
    }

    public static function markFinalized(int $id): int
    {
        self::requireLive($id);
        self::$state[$id]['finalized'] = true;
        self::$state[$id]['hmacKey'] = null;

        return 1;
    }

    public static function finalize(int $id, bool $raw): string
    {
        $entry = self::requireLive($id);
        if (null !== $entry['hmacKey']) {
            $digest = VmHashNative::incrementalHmacFinal(
                $entry['algoId'],
                $entry['ctx'],
                $entry['hmacKey'],
                $raw
            );
        } else {
            $digest = VmHashNative::incrementalFinal($entry['algoId'], $entry['ctx'], $raw);
        }
        self::$state[$id]['finalized'] = true;
        self::$state[$id]['hmacKey'] = null;

        return $digest;
    }

    public static function copy(int $id): int
    {
        $entry = self::requireLive($id);
        $newId = self::$nextId++;
        self::$state[$newId] = [
            'algo' => $entry['algo'],
            'algoId' => $entry['algoId'],
            'ctx' => VmHashNative::incrementalCopy($entry['ctx']),
            'buf' => $entry['buf'],
            'finalized' => false,
            'flags' => $entry['flags'],
            'hmacKey' => $entry['hmacKey'],
            'key' => $entry['key'],
        ];

        return $newId;
    }

    /**
     * @return array{
     *     algo: string,
     *     algoId: int,
     *     ctx: array<string, mixed>,
     *     buf: string,
     *     finalized: bool,
     *     flags: int,
     *     hmacKey: ?string,
     *     key: string
     * }
     */
    private static function requireLive(int $id): array
    {
        $entry = self::$state[$id] ?? null;
        if (null === $entry || $entry['finalized']) {
            throw new \TypeError('hash context is not valid');
        }

        return $entry;
    }

    public static function resetForTest(): void
    {
        self::$state = [];
        self::$nextId = 1;
    }
}
