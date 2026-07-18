<?php

declare(strict_types=1);

namespace PHPCompiler\ext\enchant;

/**
 * libenchant-2 FFI bridge (php-src ext/enchant/enchant.c; #6230 / #20613).
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

    /**
     * @return \FFI\CData|null EnchantDict*
     */
    public static function requestPwlDict(\FFI\CData $broker, string $filename): ?\FFI\CData
    {
        $dict = self::requireFfi()->enchant_broker_request_pwl_dict($broker, $filename);

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

    public static function setOrdering(\FFI\CData $broker, string $tag, string $ordering): void
    {
        self::requireFfi()->enchant_broker_set_ordering($broker, $tag, $ordering);
    }

    public static function brokerGetError(\FFI\CData $broker): ?string
    {
        $msg = self::requireFfi()->enchant_broker_get_error($broker);
        if (null === $msg || false === $msg) {
            return null;
        }

        return (string) $msg;
    }

    /**
     * @return list<array{name: string, desc: string, file: string}>
     */
    public static function brokerDescribe(\FFI\CData $broker): array
    {
        $out = [];
        self::requireFfi()->enchant_broker_describe(
            $broker,
            static function ($name, $desc, $file, $ud) use (&$out): void {
                $out[] = [
                    'name' => (string) $name,
                    'desc' => (string) $desc,
                    'file' => (string) $file,
                ];
            },
            null
        );

        return $out;
    }

    /**
     * @return list<array{lang_tag: string, provider_name: string, provider_desc: string, provider_file: string}>
     */
    public static function brokerListDicts(\FFI\CData $broker): array
    {
        $out = [];
        self::requireFfi()->enchant_broker_list_dicts(
            $broker,
            static function ($lang, $name, $desc, $file, $ud) use (&$out): void {
                $out[] = [
                    'lang_tag' => (string) $lang,
                    'provider_name' => (string) $name,
                    'provider_desc' => (string) $desc,
                    'provider_file' => (string) $file,
                ];
            },
            null
        );

        return $out;
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

    public static function dictAdd(\FFI\CData $dict, string $word): void
    {
        self::requireFfi()->enchant_dict_add($dict, $word, \strlen($word));
    }

    public static function dictRemove(\FFI\CData $dict, string $word): void
    {
        self::requireFfi()->enchant_dict_remove($dict, $word, \strlen($word));
    }

    public static function dictAddToSession(\FFI\CData $dict, string $word): void
    {
        self::requireFfi()->enchant_dict_add_to_session($dict, $word, \strlen($word));
    }

    public static function dictRemoveFromSession(\FFI\CData $dict, string $word): void
    {
        self::requireFfi()->enchant_dict_remove_from_session($dict, $word, \strlen($word));
    }

    public static function dictIsAdded(\FFI\CData $dict, string $word): bool
    {
        return 0 !== (int) self::requireFfi()->enchant_dict_is_added($dict, $word, \strlen($word));
    }

    public static function dictStoreReplacement(\FFI\CData $dict, string $misspelled, string $correct): void
    {
        self::requireFfi()->enchant_dict_store_replacement(
            $dict,
            $misspelled,
            \strlen($misspelled),
            $correct,
            \strlen($correct)
        );
    }

    public static function dictGetError(\FFI\CData $dict): ?string
    {
        $msg = self::requireFfi()->enchant_dict_get_error($dict);
        if (null === $msg || false === $msg) {
            return null;
        }

        return (string) $msg;
    }

    /**
     * @return array{lang: string, name: string, desc: string, file: string}|null
     */
    public static function dictDescribe(\FFI\CData $dict): ?array
    {
        $out = null;
        self::requireFfi()->enchant_dict_describe(
            $dict,
            static function ($lang, $name, $desc, $file, $ud) use (&$out): void {
                $out = [
                    'lang' => (string) $lang,
                    'name' => (string) $name,
                    'desc' => (string) $desc,
                    'file' => (string) $file,
                ];
            },
            null
        );

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
typedef void (*EnchantBrokerDescribeFn)(const char *name, const char *desc, const char *file, void *ud);
typedef void (*EnchantDictDescribeFn)(const char *lang, const char *name, const char *desc, const char *file, void *ud);
EnchantBroker *enchant_broker_init(void);
void enchant_broker_free(EnchantBroker *broker);
EnchantDict *enchant_broker_request_dict(EnchantBroker *broker, const char *tag);
EnchantDict *enchant_broker_request_pwl_dict(EnchantBroker *broker, const char *filename);
void enchant_broker_free_dict(EnchantBroker *broker, EnchantDict *dict);
int enchant_broker_dict_exists(EnchantBroker *broker, const char *tag);
void enchant_broker_set_ordering(EnchantBroker *broker, const char *tag, const char *ordering);
const char *enchant_broker_get_error(EnchantBroker *broker);
void enchant_broker_describe(EnchantBroker *broker, EnchantBrokerDescribeFn fn, void *ud);
void enchant_broker_list_dicts(EnchantBroker *broker, EnchantDictDescribeFn fn, void *ud);
int enchant_dict_check(EnchantDict *dict, const char *word, long len);
char **enchant_dict_suggest(EnchantDict *dict, const char *word, long len, size_t *out_n_suggs);
void enchant_dict_free_string_list(EnchantDict *dict, char **string_list);
void enchant_dict_add(EnchantDict *dict, const char *word, long len);
void enchant_dict_remove(EnchantDict *dict, const char *word, long len);
void enchant_dict_add_to_session(EnchantDict *dict, const char *word, long len);
void enchant_dict_remove_from_session(EnchantDict *dict, const char *word, long len);
int enchant_dict_is_added(EnchantDict *dict, const char *word, long len);
void enchant_dict_store_replacement(EnchantDict *dict, const char *mis, long mis_len, const char *cor, long cor_len);
const char *enchant_dict_get_error(EnchantDict *dict);
void enchant_dict_describe(EnchantDict *dict, EnchantDictDescribeFn fn, void *ud);
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
