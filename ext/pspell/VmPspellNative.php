<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pspell;

/**
 * libaspell FFI bridge (php-src ext/pspell/pspell.c; #6294).
 *
 * Uses the Aspell C API (`new_aspell_*`) — php-src's `new_pspell_*` names are
 * compatibility aliases over the same library. No runtime/*.c growth.
 */
final class VmPspellNative
{
    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * Create a speller for the given language/options.
     *
     * @return array{ok: true, speller: \FFI\CData}|array{ok: false, message: string}
     */
    public static function newSpeller(
        string $language,
        string $spelling,
        string $jargon,
        string $encoding,
        int $mode
    ): array {
        $ffi = self::requireFfi();
        $config = $ffi->new_aspell_config();
        $ffi->aspell_config_replace($config, 'language-tag', $language);
        if ('' !== $spelling) {
            $ffi->aspell_config_replace($config, 'spelling', $spelling);
        }
        if ('' !== $jargon) {
            $ffi->aspell_config_replace($config, 'jargon', $jargon);
        }
        if ('' !== $encoding) {
            $ffi->aspell_config_replace($config, 'encoding', $encoding);
        }
        if (0 !== $mode) {
            $speed = $mode & PspellConstants::SPEED_MASK;
            if (PspellConstants::PSPELL_FAST === $speed) {
                $ffi->aspell_config_replace($config, 'sug-mode', 'fast');
            } elseif (PspellConstants::PSPELL_NORMAL === $speed) {
                $ffi->aspell_config_replace($config, 'sug-mode', 'normal');
            } elseif (PspellConstants::PSPELL_BAD_SPELLERS === $speed) {
                $ffi->aspell_config_replace($config, 'sug-mode', 'bad-spellers');
            }
            if (0 !== ($mode & PspellConstants::PSPELL_RUN_TOGETHER)) {
                $ffi->aspell_config_replace($config, 'run-together', 'true');
            }
        }

        $ret = $ffi->new_aspell_speller($config);
        $ffi->delete_aspell_config($config);

        if (0 !== (int) $ffi->aspell_error_number($ret)) {
            $message = (string) $ffi->aspell_error_message($ret);
            $ffi->delete_aspell_can_have_error($ret);

            return ['ok' => false, 'message' => $message];
        }

        $speller = $ffi->to_aspell_speller($ret);

        return ['ok' => true, 'speller' => $speller];
    }

    public static function deleteSpeller(\FFI\CData $speller): void
    {
        self::requireFfi()->delete_aspell_speller($speller);
    }

    public static function check(\FFI\CData $speller, string $word): bool
    {
        return 0 !== (int) self::requireFfi()->aspell_speller_check($speller, $word, -1);
    }

    /**
     * @return list<string>|null null when aspell reports an error
     */
    public static function suggest(\FFI\CData $speller, string $word): ?array
    {
        $ffi = self::requireFfi();
        $wl = $ffi->aspell_speller_suggest($speller, $word, -1);
        if (null === $wl) {
            return null;
        }
        $els = $ffi->aspell_word_list_elements($wl);
        $out = [];
        while (true) {
            $sug = $ffi->aspell_string_enumeration_next($els);
            if (null === $sug || false === $sug) {
                break;
            }
            $out[] = (string) $sug;
        }
        $ffi->delete_aspell_string_enumeration($els);

        return $out;
    }

    public static function spellerErrorMessage(\FFI\CData $speller): string
    {
        $msg = self::requireFfi()->aspell_speller_error_message($speller);

        return null === $msg || false === $msg ? '' : (string) $msg;
    }

    public static function spellerErrorNumber(\FFI\CData $speller): int
    {
        return (int) self::requireFfi()->aspell_speller_error_number($speller);
    }

    /** @return \FFI\CData AspellConfig* */
    public static function newConfig(): \FFI\CData
    {
        return self::requireFfi()->new_aspell_config();
    }

    public static function deleteConfig(\FFI\CData $config): void
    {
        self::requireFfi()->delete_aspell_config($config);
    }

    public static function configReplace(\FFI\CData $config, string $key, string $value): void
    {
        self::requireFfi()->aspell_config_replace($config, $key, $value);
    }

    /**
     * @return array{ok: true, speller: \FFI\CData}|array{ok: false, message: string}
     */
    public static function newSpellerFromConfig(\FFI\CData $config): array
    {
        $ffi = self::requireFfi();
        $ret = $ffi->new_aspell_speller($config);
        if (0 !== (int) $ffi->aspell_error_number($ret)) {
            $message = (string) $ffi->aspell_error_message($ret);
            $ffi->delete_aspell_can_have_error($ret);

            return ['ok' => false, 'message' => $message];
        }

        return ['ok' => true, 'speller' => $ffi->to_aspell_speller($ret)];
    }

    public static function addToPersonal(\FFI\CData $speller, string $word): bool
    {
        $ffi = self::requireFfi();
        $ffi->aspell_speller_add_to_personal($speller, $word, -1);

        return 0 === self::spellerErrorNumber($speller);
    }

    public static function addToSession(\FFI\CData $speller, string $word): bool
    {
        $ffi = self::requireFfi();
        $ffi->aspell_speller_add_to_session($speller, $word, -1);

        return 0 === self::spellerErrorNumber($speller);
    }

    public static function clearSession(\FFI\CData $speller): bool
    {
        $ffi = self::requireFfi();
        $ffi->aspell_speller_clear_session($speller);

        return 0 === self::spellerErrorNumber($speller);
    }

    public static function saveWordlists(\FFI\CData $speller): bool
    {
        $ffi = self::requireFfi();
        $ffi->aspell_speller_save_all_word_lists($speller);

        return 0 === self::spellerErrorNumber($speller);
    }

    public static function storeReplacement(\FFI\CData $speller, string $miss, string $corr): bool
    {
        $ffi = self::requireFfi();
        $ffi->aspell_speller_store_replacement($speller, $miss, -1, $corr, -1);

        return 0 === self::spellerErrorNumber($speller);
    }

    /**
     * Create speller with optional personal wordlist path (php-src pspell_new_personal).
     *
     * @return array{ok: true, speller: \FFI\CData}|array{ok: false, message: string}
     */
    public static function newSpellerPersonal(
        string $personal,
        string $language,
        string $spelling,
        string $jargon,
        string $encoding,
        int $mode
    ): array {
        $ffi = self::requireFfi();
        $config = $ffi->new_aspell_config();
        $ffi->aspell_config_replace($config, 'personal', $personal);
        $ffi->aspell_config_replace($config, 'save-repl', 'false');
        $ffi->aspell_config_replace($config, 'language-tag', $language);
        if ('' !== $spelling) {
            $ffi->aspell_config_replace($config, 'spelling', $spelling);
        }
        if ('' !== $jargon) {
            $ffi->aspell_config_replace($config, 'jargon', $jargon);
        }
        if ('' !== $encoding) {
            $ffi->aspell_config_replace($config, 'encoding', $encoding);
        }
        if (0 !== $mode) {
            $speed = $mode & PspellConstants::SPEED_MASK;
            if (PspellConstants::PSPELL_FAST === $speed) {
                $ffi->aspell_config_replace($config, 'sug-mode', 'fast');
            } elseif (PspellConstants::PSPELL_NORMAL === $speed) {
                $ffi->aspell_config_replace($config, 'sug-mode', 'normal');
            } elseif (PspellConstants::PSPELL_BAD_SPELLERS === $speed) {
                $ffi->aspell_config_replace($config, 'sug-mode', 'bad-spellers');
            }
            if (0 !== ($mode & PspellConstants::PSPELL_RUN_TOGETHER)) {
                $ffi->aspell_config_replace($config, 'run-together', 'true');
            }
        }
        $ret = $ffi->new_aspell_speller($config);
        $ffi->delete_aspell_config($config);
        if (0 !== (int) $ffi->aspell_error_number($ret)) {
            $message = (string) $ffi->aspell_error_message($ret);
            $ffi->delete_aspell_can_have_error($ret);

            return ['ok' => false, 'message' => $message];
        }

        return ['ok' => true, 'speller' => $ffi->to_aspell_speller($ret)];
    }

    /** @return \FFI */
    private static function requireFfi()
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            throw new \LogicException('pspell requires libaspell FFI (#6294)');
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
typedef struct AspellConfig AspellConfig;
typedef struct AspellSpeller AspellSpeller;
typedef struct AspellCanHaveError AspellCanHaveError;
typedef struct AspellWordList AspellWordList;
typedef struct AspellStringEnumeration AspellStringEnumeration;
AspellConfig *new_aspell_config(void);
int aspell_config_replace(AspellConfig *ths, const char *key, const char *value);
void delete_aspell_config(AspellConfig *ths);
AspellCanHaveError *new_aspell_speller(AspellConfig *config);
int aspell_error_number(const AspellCanHaveError *ths);
const char *aspell_error_message(const AspellCanHaveError *ths);
void delete_aspell_can_have_error(AspellCanHaveError *ths);
AspellSpeller *to_aspell_speller(AspellCanHaveError *obj);
void delete_aspell_speller(AspellSpeller *ths);
int aspell_speller_check(AspellSpeller *ths, const char *word, int word_size);
const AspellWordList *aspell_speller_suggest(AspellSpeller *ths, const char *word, int word_size);
const char *aspell_speller_error_message(const AspellSpeller *ths);
unsigned int aspell_speller_error_number(const AspellSpeller *ths);
int aspell_speller_add_to_personal(AspellSpeller *ths, const char *word, int word_size);
int aspell_speller_add_to_session(AspellSpeller *ths, const char *word, int word_size);
int aspell_speller_clear_session(AspellSpeller *ths);
int aspell_speller_save_all_word_lists(AspellSpeller *ths);
int aspell_speller_store_replacement(AspellSpeller *ths, const char *mis, int mis_size, const char *cor, int cor_size);
AspellStringEnumeration *aspell_word_list_elements(const AspellWordList *ths);
const char *aspell_string_enumeration_next(AspellStringEnumeration *ths);
void delete_aspell_string_enumeration(AspellStringEnumeration *ths);
CDEF;

        foreach (['libaspell.so.15', 'libaspell.so', 'libaspell.so.16'] as $lib) {
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
