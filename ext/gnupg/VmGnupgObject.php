<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gnupg;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * gnupg object state (PECL gnupg / php_gnupg.h; #6668).
 */
final class VmGnupgObject
{
    public const CLASS_LC = 'gnupg';

    public const CLASS_NAME = 'gnupg';

    /**
     * @var array<int, array{
     *   ctx: \FFI\CData,
     *   encrypt_keys: list<\FFI\CData>,
     *   decrypt_keys: array<string, string>,
     *   sign_keys: array<string, string>,
     *   signmode: int,
     *   err: int,
     *   errortxt: ?string,
     *   errormode: int,
     *   freed: bool
     * }>
     */
    private static array $state = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }
        $entry = new ClassEntry(self::CLASS_NAME);
        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function wrap(Context $ctx, \FFI\CData $nativeCtx): Variable
    {
        self::registerClass($ctx);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'ctx' => $nativeCtx,
            'encrypt_keys' => [],
            'decrypt_keys' => [],
            'sign_keys' => [],
            'signmode' => VmGnupgNative::SIG_MODE_CLEAR,
            'err' => 0,
            'errortxt' => null,
            'errormode' => 3,
            'freed' => false,
        ];
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    public static function isLive(ObjectEntry $object): bool
    {
        return isset(self::$state[$object->id]) && !self::$state[$object->id]['freed'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function &state(ObjectEntry $object): array
    {
        if (!self::isLive($object)) {
            throw new \ValueError('Invalid or uninitialized gnupg object');
        }

        return self::$state[$object->id];
    }

    public static function ctx(ObjectEntry $object): \FFI\CData
    {
        return self::state($object)['ctx'];
    }

    public static function setErr(ObjectEntry $object, int $err): void
    {
        self::state($object)['err'] = $err;
    }

    public static function reportError(ObjectEntry $object, string $message, int $err = 0): void
    {
        $st = &self::state($object);
        if (0 !== $err) {
            $st['err'] = $err;
        }
        switch ($st['errormode']) {
            case 1:
                \trigger_error($message, E_USER_WARNING);
                break;
            case 2:
                throw new \Exception($message);
            default:
                $st['errortxt'] = $message;
        }
    }

    public static function clearEncryptKeys(ObjectEntry $object): void
    {
        $st = &self::state($object);
        foreach ($st['encrypt_keys'] as $key) {
            VmGnupgNative::keyUnref($key);
        }
        $st['encrypt_keys'] = [];
    }

    public static function clearDecryptKeys(ObjectEntry $object): void
    {
        self::state($object)['decrypt_keys'] = [];
    }

    public static function clearSignKeys(ObjectEntry $object): void
    {
        VmGnupgNative::signersClear(self::ctx($object));
        self::state($object)['sign_keys'] = [];
    }

    public static function free(ObjectEntry $object): void
    {
        if (!isset(self::$state[$object->id]) || self::$state[$object->id]['freed']) {
            throw new \ValueError('Invalid or uninitialized gnupg object');
        }
        self::clearEncryptKeys($object);
        self::clearSignKeys($object);
        VmGnupgNative::ctxRelease(self::ctx($object));
        self::$state[$object->id]['freed'] = true;
    }
}
