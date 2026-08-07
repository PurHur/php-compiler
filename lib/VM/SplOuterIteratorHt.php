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
            // Packed fixed slots including null pads — nextFreeElement walk (#28640 / #26793).
            'splfixedarray',
        ];
    }

    /**
     * SplStack stores push order in `__spl_ht` but iterates LIFO (#28705 / #27311).
     *
     * Still uses {@see splBackingHashtable}; foreach walks nextFreeElement descending.
     */
    public static function isReverseHtWalk(?string $containerUserType): bool
    {
        if (null === $containerUserType || '' === $containerUserType) {
            return false;
        }

        return 'splstack' === strtolower(ltrim($containerUserType, '\\'));
    }

    public static function isHtBacked(?string $containerUserType): bool
    {
        if (null === $containerUserType || '' === $containerUserType) {
            return false;
        }
        $lc = strtolower(ltrim($containerUserType, '\\'));

        return \in_array($lc, self::classNamesLc(), true) || self::isReverseHtWalk($containerUserType);
    }
}
