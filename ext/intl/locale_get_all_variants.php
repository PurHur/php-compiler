<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** locale_get_all_variants() — php-src alias of Locale::getAllVariants (#20755). */
final class locale_get_all_variants extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'locale_get_all_variants() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        $locale = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'locale_get_all_variants',
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

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \RuntimeException('locale_get_all_variants() JIT lowering not implemented; use VM');
    }
}
