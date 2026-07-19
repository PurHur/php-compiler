<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** Locale::getAllVariants() — php-src locale_get_all_variants (#20755). */
final class LocaleGetAllVariants extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getAllVariants');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'Locale::getAllVariants() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        $locale = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'Locale::getAllVariants',
            0,
            'locale'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $variants = VmLocale::getAllVariants($locale);
        $ht = new HashTable();
        foreach ($variants as $v) {
            $item = new Variable(Variable::TYPE_STRING);
            $item->string($v);
            $ht->append($item);
        }
        $frame->returnVar->array($ht);
    }
}
