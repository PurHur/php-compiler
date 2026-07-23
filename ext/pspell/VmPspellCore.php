<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pspell;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Shared pspell_* semantics (php-src ext/pspell/pspell.c; #6294, #22229).
 */
final class VmPspellCore
{
    /**
     * @return Variable|false PSpell\Dictionary object variable, or false on failure
     */
    public static function newDictionary(
        Context $ctx,
        ?Frame $frame,
        string $language,
        string $spelling = '',
        string $jargon = '',
        string $encoding = '',
        int $mode = 0
    ): Variable|false {
        if (!VmPspellNative::available()) {
            return false;
        }
        $result = VmPspellNative::newSpeller($language, $spelling, $jargon, $encoding, $mode);
        if (!$result['ok']) {
            self::emitWarning(
                $frame,
                'PSPELL couldn\'t open the dictionary. reason: '.$result['message']
            );

            return false;
        }

        return VmPspellDictionary::wrap($result['speller'], $ctx);
    }

    /**
     * @return Variable|false
     */
    public static function newDictionaryPersonal(
        Context $ctx,
        ?Frame $frame,
        string $personal,
        string $language,
        string $spelling = '',
        string $jargon = '',
        string $encoding = '',
        int $mode = 0
    ): Variable|false {
        if (!VmPspellNative::available()) {
            return false;
        }
        $result = VmPspellNative::newSpellerPersonal(
            $personal,
            $language,
            $spelling,
            $jargon,
            $encoding,
            $mode
        );
        if (!$result['ok']) {
            self::emitWarning(
                $frame,
                'PSPELL couldn\'t open the dictionary. reason: '.$result['message']
            );

            return false;
        }

        return VmPspellDictionary::wrap($result['speller'], $ctx);
    }

    /**
     * @return Variable|false
     */
    public static function newDictionaryFromConfig(
        Context $ctx,
        ?Frame $frame,
        ObjectEntry $config
    ): Variable|false {
        if (!VmPspellNative::available()) {
            return false;
        }
        $result = VmPspellNative::newSpellerFromConfig(VmPspellConfig::native($config));
        if (!$result['ok']) {
            self::emitWarning(
                $frame,
                'PSPELL couldn\'t open the dictionary. reason: '.$result['message']
            );

            return false;
        }

        return VmPspellDictionary::wrap($result['speller'], $ctx);
    }

    public static function createConfig(
        Context $ctx,
        string $language,
        string $spelling = '',
        string $jargon = '',
        string $encoding = ''
    ): Variable {
        $config = VmPspellNative::newConfig();
        VmPspellNative::configReplace($config, 'language-tag', $language);
        if ('' !== $spelling) {
            VmPspellNative::configReplace($config, 'spelling', $spelling);
        }
        if ('' !== $jargon) {
            VmPspellNative::configReplace($config, 'jargon', $jargon);
        }
        if ('' !== $encoding) {
            VmPspellNative::configReplace($config, 'encoding', $encoding);
        }
        VmPspellNative::configReplace($config, 'save-repl', 'false');

        return VmPspellConfig::wrap($config, $ctx);
    }

    public static function configReplace(ObjectEntry $config, string $key, string $value): bool
    {
        VmPspellNative::configReplace(VmPspellConfig::native($config), $key, $value);

        return true;
    }

    public static function configMode(ObjectEntry $config, int $mode): bool
    {
        if (PspellConstants::PSPELL_FAST === $mode) {
            return self::configReplace($config, 'sug-mode', 'fast');
        }
        if (PspellConstants::PSPELL_NORMAL === $mode) {
            return self::configReplace($config, 'sug-mode', 'normal');
        }
        if (PspellConstants::PSPELL_BAD_SPELLERS === $mode) {
            return self::configReplace($config, 'sug-mode', 'bad-spellers');
        }

        return true;
    }

    public static function check(ObjectEntry $dict, string $word): bool
    {
        return VmPspellNative::check(VmPspellDictionary::native($dict), $word);
    }

    /**
     * @return HashTable|false
     */
    public static function suggest(ObjectEntry $dict, string $word, ?Frame $frame): HashTable|false
    {
        $native = VmPspellDictionary::native($dict);
        $list = VmPspellNative::suggest($native, $word);
        if (null === $list) {
            $msg = VmPspellNative::spellerErrorMessage($native);
            self::emitWarning(
                $frame,
                'PSPELL had a problem. details: '.$msg
            );

            return false;
        }
        $ht = new HashTable();
        foreach ($list as $i => $sugg) {
            $slot = new Variable();
            $slot->string($sugg);
            $ht->add((string) $i, $slot);
        }

        return $ht;
    }

    public static function addToPersonal(ObjectEntry $dict, string $word, ?Frame $frame): bool
    {
        if ('' === $word) {
            return false;
        }
        $native = VmPspellDictionary::native($dict);
        if (VmPspellNative::addToPersonal($native, $word)) {
            return true;
        }
        self::emitWarning(
            $frame,
            'pspell_add_to_personal() gave error: '.VmPspellNative::spellerErrorMessage($native)
        );

        return false;
    }

    public static function addToSession(ObjectEntry $dict, string $word, ?Frame $frame): bool
    {
        if ('' === $word) {
            return false;
        }
        $native = VmPspellDictionary::native($dict);
        if (VmPspellNative::addToSession($native, $word)) {
            return true;
        }
        self::emitWarning(
            $frame,
            'pspell_add_to_session() gave error: '.VmPspellNative::spellerErrorMessage($native)
        );

        return false;
    }

    public static function clearSession(ObjectEntry $dict, ?Frame $frame): bool
    {
        $native = VmPspellDictionary::native($dict);
        if (VmPspellNative::clearSession($native)) {
            return true;
        }
        self::emitWarning(
            $frame,
            'pspell_clear_session() gave error: '.VmPspellNative::spellerErrorMessage($native)
        );

        return false;
    }

    public static function saveWordlist(ObjectEntry $dict, ?Frame $frame): bool
    {
        $native = VmPspellDictionary::native($dict);
        if (VmPspellNative::saveWordlists($native)) {
            return true;
        }
        self::emitWarning(
            $frame,
            'pspell_save_wordlist() gave error: '.VmPspellNative::spellerErrorMessage($native)
        );

        return false;
    }

    public static function storeReplacement(
        ObjectEntry $dict,
        string $miss,
        string $corr,
        ?Frame $frame
    ): bool {
        $native = VmPspellDictionary::native($dict);
        if (VmPspellNative::storeReplacement($native, $miss, $corr)) {
            return true;
        }
        self::emitWarning(
            $frame,
            'pspell_store_replacement() gave error: '.VmPspellNative::spellerErrorMessage($native)
        );

        return false;
    }

    private static function emitWarning(?Frame $frame, string $message): void
    {
        if (null === $frame?->vmContext) {
            @\trigger_error($message, \E_WARNING);

            return;
        }
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
