<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * RegexIterator MATCH filter for thin AOT NestedJIT (#26825).
 *
 * php-src: ext/spl/spl_iterators.c — RegexIterator::accept / MATCH mode
 */
final class RegexIteratorFilterJitHelper
{
    public static function filterMatch(HashTable $src, string $pattern): HashTable
    {
        $out = new HashTable();
        foreach ($src->iterateKeyed(true) as [$key, $value]) {
            $resolved = $value->resolveIndirect();
            if (1 !== preg_match($pattern, $resolved->toString())) {
                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($resolved);
            $key = $key->resolveIndirect();
            if (Variable::TYPE_INTEGER === $key->type) {
                $out->addIndex($key->toInt(), $copy);
            } else {
                $out->add($key->toString(), $copy);
            }
        }

        return $out;
    }
}
