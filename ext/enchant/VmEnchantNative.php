<?php

declare(strict_types=1);

namespace PHPCompiler\ext\enchant;

/**
 * libenchant-2 FFI bridge (php-src ext/enchant/enchant.c; #6230).
 *
 * No runtime/*.c growth — dictionary logic stays in PHP; C is a thin ABI only.
 */
final class VmEnchantNative
{
    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @return \FFI\CData|null EnchantBroker*
     */
    public static function brokerInit(): ?\FFI\CData
    {
        $broker = self::requireFfi()->enchant_broker_init();

        return null === $broker ? null : $broker;
    }

    public static function brokerFree(\FFI\CData $broker): void
    {
        self::requireFfi()->enchant_broker_free($broker);
    }

    /**
     * @return \FFI\CData|null EnchantDict*
     */
    public static function requestDict(\FFI\CData $broker, string $tag): ?\FFI\CData
    {
        $dict = self::requireFfi()->enchant_broker_request_dict($broker, $tag);

        return null === $dict ? null : $dict;
    }

    public static function freeDict(\FFI\CData $broker, \FFI\CData $dict): void
    {
        self::requireFfi()->enchant_broker_free_dict($broker, $dict);
    }

    public static function dictExists(\FFI\CData $broker, string $tag): bool
    {
        return 0 !== (int) self::requireFfi()->enchant_broker_dict_exists($broker, $tag);
    }

    /**
     * libenchant: 0 = correctly spelled, non-zero = misspelled.
     */
    public static function dictCheckRaw(\FFI\CData $dict, string $word): int
    {
        return (int) self::requireFfi()->enchant_dict_check($dict, $word, \strlen($word));
    }

    /**
     * @return list<string>
     */
    public static function dictSuggest(\FFI\CData $dict, string $word): array
    {
        $ffi = self::requireFfi();
        $n = \FFI::new('size_t');
        $list = $ffi->enchant_dict_suggest($dict, $word, \strlen($word), \FFI::addr($n));
        if (null === $list) {
            return [];
        }
        $count = (int) $n->cdata;
        $out = [];
        for ($i = 0; $i < $count; ++$i) {
            $ptr = $list[$i];
            if (null === $ptr) {
                continue;
            }
            try {
                $out[] = \FFI::string($ptr);
            } catch (\Throwable) {
            }
        }
        $ffi->enchant_dict_free_string_list($dict, $list);

        return $out;
    }

    /** @return \FFI */
    private static function requireFfi()
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            throw new \LogicException('enchant requires libenchant FFI (#6230)');
        }

        return $ffi;
    }

    /** @return \FFI|null */
    private static function ffi()
    {
        if (!self::ffiEnabled()) {
            return null;
        }
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef struct str_enchant_broker EnchantBroker;
typedef struct str_enchant_dict EnchantDict;
EnchantBroker *enchant_broker_init(void);
void enchant_broker_free(EnchantBroker *broker);
EnchantDict *enchant_broker_request_dict(EnchantBroker *broker, const char *tag);
void enchant_broker_free_dict(EnchantBroker *broker, EnchantDict *dict);
int enchant_broker_dict_exists(EnchantBroker *broker, const char *tag);
int enchant_dict_check(EnchantDict *dict, const char *word, long len);
char **enchant_dict_suggest(EnchantDict *dict, const char *word, long len, size_t *out_n_suggs);
void enchant_dict_free_string_list(EnchantDict *dict, char **string_list);
CDEF;

        foreach (['libenchant-2.so.2', 'libenchant-2.so', 'libenchant.so.1', 'libenchant.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return true;
    }
}
