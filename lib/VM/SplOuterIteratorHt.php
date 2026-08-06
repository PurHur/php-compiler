<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * SPL outer iterators that snapshot into `__spl_ht` for thin AOT (#26825).
 *
 * php-src: ext/spl/spl_iterators.c — LimitIterator / AppendIterator / RegexIterator
 */
final class SplOuterIteratorHt
{
    public const PROP_HT = '__spl_ht';

    /** @return list<string> */
    public static function classNamesLc(): array
    {
        return [
            'limititerator',
            'appenditerator',
            'regexiterator',
            'callbackfilteriterator',
            'cachingiterator',
            'arrayiterator',
            'recursivearrayiterator',
            'recursiveiteratoriterator',
            'arrayobject',
            'parentiterator',
            'multipleiterator',
            'recursivetreeiterator',
            // FIFO packed `__spl_ht` deque — not SplStack (LIFO foreach) (#27311 / #26790).
            'spldoublylinkedlist',
            'splqueue',
        ];
    }

    public static function isHtBacked(?string $containerUserType): bool
    {
        if (null === $containerUserType || '' === $containerUserType) {
            return false;
        }

        return \in_array(strtolower(ltrim($containerUserType, '\\')), self::classNamesLc(), true);
    }
}
