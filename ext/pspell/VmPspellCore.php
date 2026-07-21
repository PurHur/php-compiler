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
 * Shared pspell_* semantics (php-src ext/pspell/pspell.c; #6294).
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
