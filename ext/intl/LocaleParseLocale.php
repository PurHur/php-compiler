<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** Locale::parseLocale() — php-src locale_parse (#20738). */
final class LocaleParseLocale extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('parseLocale');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'Locale::parseLocale() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        $locale = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'Locale::parseLocale',
            0,
            'locale'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmLocale::parseLocale($locale);
        if (null === $result) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->array(self::assocToHashTable($result));
    }

    /** @param array<string, string> $values */
    public static function assocToHashTable(array $values): HashTable
    {
        $ht = new HashTable();
        foreach ($values as $key => $value) {
            $slot = new Variable();
            $slot->string($value);
            $ht->add((string) $key, $slot);
        }

        return $ht;
    }
}
