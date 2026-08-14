<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmHashNative;
use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Incremental hash context lifecycle (php-src ext/hash/hash.c; issue #7174).
 *
 * Opaque HashContext objects backed by VmHashNative incremental digests — no C growth.
 */
final class VmHashContext
{
    public const CLASS_LC = 'hashcontext';

    /** php-src PHP_HASH_HMAC / HASH_HMAC (ext/hash/php_hash.h; #23585). */
    public const HASH_HMAC = 1;

    /**
     * @var array<int, array{
     *     algo: int,
     *     ctx: array<string, mixed>,
     *     finalized: bool,
     *     flags: int,
     *     hmacKey: ?string
     * }>
     */
    private static array $store = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }
        $entry = new \PHPCompiler\VM\ClassEntry('HashContext');
        $entry->isInternal = true;
        // php-src `final class HashContext` (ext/hash/hash.stub.php; #28384).
        $entry->isFinal = true;
        // PHP 8.4+ only — Zend 8.2/8.3 stubs omit HashContext::__debugInfo (#22563, re-#7084).
        if (CompilerVersion::supportsHashContextDebugInfo()) {
            $entry->methods['__debuginfo'] = new HashContextDebugInfo();
            $entry->methodNames['__debuginfo'] = '__debugInfo';
        }
        HashContextSerializeSupport::registerMagicMethods($entry);
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function hasStore(ObjectEntry $entry): bool
    {
        return isset(self::$store[$entry->id])
            && self::CLASS_LC === strtolower($entry->class->name);
    }

    /**
     * @return array{algo: int, ctx: array<string, mixed>, finalized: bool, flags: int, hmacKey: ?string}|null
     */
    public static function exportStoreState(ObjectEntry $entry): ?array
    {
        $state = self::$store[$entry->id] ?? null;
        if (null === $state || self::CLASS_LC !== strtolower($entry->class->name)) {
            return null;
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $ctx
     */
    public static function bindStore(
        ObjectEntry $entry,
        int $algoId,
        array $ctx,
        bool $finalized = false,
        int $flags = 0,
        ?string $hmacKey = null
    ): void {
        self::$store[$entry->id] = [
            'algo' => $algoId,
            'ctx' => $ctx,
            'finalized' => $finalized,
            'flags' => $flags,
            'hmacKey' => $hmacKey,
        ];
    }

    public static function debugInfoAlgoName(ObjectEntry $entry): string
    {
        $state = self::$store[$entry->id] ?? null;
        if (null === $state || self::CLASS_LC !== strtolower($entry->class->name)) {
            throw new \LogicException('HashContext::__debugInfo() expects a HashContext receiver');
        }

        return VmHashNative::resolveAlgoName($state['algo']);
    }

    /**
     * hash_init() — php-src ext/hash/hash.c (#7174, #23585).
     *
     * @param int $flags PHP_HASH_HMAC bit and reserved flags
     */
    public static function init(Context $vmCtx, string $algo, int $flags = 0, string $key = ''): Variable
    {
        $algoId = VmHashNative::resolveAlgoId($algo);
        if (0 === $algoId) {
            throw new \ValueError('hash_init(): Argument #1 ($algo) must be a valid hashing algorithm');
        }
        $hmac = 0 !== ($flags & self::HASH_HMAC);
        if ($hmac) {
            if (!VmHashNative::isCryptographicAlgoId($algoId)) {
                throw new \ValueError(
                    'hash_init(): Argument #1 ($algo) must be a cryptographic hashing algorithm if HMAC is requested'
                );
            }
            if ('' === $key) {
                throw new \ValueError(
                    \PHPCompiler\ext\standard\VmString::hashInitEmptyHmacKeyValueErrorMessage()
                );
            }
        }
        $class = $vmCtx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('HashContext is not registered in this compiler build');
        }
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        if ($hmac) {
            $prepared = VmHashNative::incrementalHmacCreate($algoId, $key);
            self::$store[$entry->id] = [
                'algo' => $algoId,
                'ctx' => $prepared['ctx'],
                'finalized' => false,
                'flags' => $flags,
                'hmacKey' => $prepared['hmacKey'],
            ];
        } else {
            self::$store[$entry->id] = [
                'algo' => $algoId,
                'ctx' => VmHashNative::incrementalCreate($algoId),
                'finalized' => false,
                'flags' => $flags,
                'hmacKey' => null,
            ];
        }
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function update(ObjectEntry $entry, string $data): void
    {
        self::requireLiveContext($entry, 'hash_update', 1);
        VmHashNative::incrementalUpdate(
            self::$store[$entry->id]['algo'],
            self::$store[$entry->id]['ctx'],
            $data
        );
    }

    /**
     * hash_update_stream() — read $handle in chunks into incremental digest (#6681).
     *
     * php-src: ext/hash/hash.c — PHP_FUNCTION(hash_update_stream)
     *
     * @return int|false bytes read, or false on stream read failure
     */
    public static function updateFromStream(ObjectEntry $entry, int $handle, int $length = -1)
    {
        self::requireLiveContext($entry, 'hash_update_stream', 1);
        if (!VmFs::isValidHandle($handle)) {
            throw new \TypeError('hash_update_stream(): supplied resource is not a valid stream resource');
        }
        if (0 === $length) {
            return 0;
        }

        $total = 0;
        $chunkSize = 8192;
        while ($length < 0 || $total < $length) {
            $toRead = $chunkSize;
            if ($length >= 0) {
                $remaining = $length - $total;
                if ($remaining <= 0) {
                    break;
                }
                if ($toRead > $remaining) {
                    $toRead = $remaining;
                }
            }

            $data = VmFs::fread($handle, $toRead);
            if (false === $data) {
                if (VmFs::feof($handle)) {
                    break;
                }

                return false;
            }
            if ('' === $data) {
                break;
            }

            self::update($entry, $data);
            $total += \strlen($data);
        }

        return $total;
    }

    public static function final(ObjectEntry $entry, bool $raw = false): string
    {
        $state = self::requireLiveContext($entry, 'hash_final', 1);
        if (null !== $state['hmacKey']) {
            $result = VmHashNative::incrementalHmacFinal(
                $state['algo'],
                $state['ctx'],
                $state['hmacKey'],
                $raw
            );
        } else {
            $result = VmHashNative::incrementalFinal($state['algo'], $state['ctx'], $raw);
        }
        self::$store[$entry->id]['finalized'] = true;
        self::$store[$entry->id]['hmacKey'] = null;

        return $result;
    }

    public static function copy(ObjectEntry $entry): Variable
    {
        $state = self::requireLiveContext($entry, 'hash_copy', 1);
        $class = $entry->class;
        $clone = new ObjectEntry($class);
        $clone->constructed = true;
        self::$store[$clone->id] = [
            'algo' => $state['algo'],
            'ctx' => VmHashNative::incrementalCopy($state['ctx']),
            'finalized' => false,
            'flags' => $state['flags'],
            'hmacKey' => $state['hmacKey'],
        ];
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($clone);

        return $var;
    }

    /**
     * @return array{algo: int, ctx: array<string, mixed>, finalized: bool, flags: int, hmacKey: ?string}
     */
    private static function requireLiveContext(ObjectEntry $entry, string $function, int $argNum): array
    {
        $state = self::$store[$entry->id] ?? null;
        if (null === $state || self::CLASS_LC !== strtolower($entry->class->name)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($context) must be of type HashContext, %s given',
                $function,
                $argNum,
                $entry->class->name
            ));
        }
        if ($state['finalized']) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($context) must be a valid, non-finalized HashContext',
                $function,
                $argNum
            ));
        }

        return $state;
    }

    public static function requireHashContext(
        Variable $var,
        string $function,
        int $argNum,
        string $paramName = 'context'
    ): ObjectEntry {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type HashContext, %s given',
                $function,
                $argNum,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type HashContext, %s given',
                $function,
                $argNum,
                $paramName,
                VmStreamArg::debugTypeName($var)
            ));
        }
        $object = $var->toObject();
        if (self::CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type HashContext, %s given',
                $function,
                $argNum,
                $paramName,
                $object->class->name
            ));
        }
        if (!isset(self::$store[$object->id])) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type HashContext, %s given',
                $function,
                $argNum,
                $paramName,
                $object->class->name
            ));
        }

        return $object;
    }
}
