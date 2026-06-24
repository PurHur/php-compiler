<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** Convert host preg_* $matches arrays to VM hashtables (issue #4417). */
final class VmPregMatches
{
    /**
     * @param array<int, mixed> $hostMatches
     */
    public static function hostMatchesToHashTable(array $hostMatches, int $flags): HashTable
    {
        $offsetCapture = 0 !== ($flags & StdlibConstants::PREG_OFFSET_CAPTURE);
        $unmatchedNull = 0 !== ($flags & StdlibConstants::PREG_UNMATCHED_AS_NULL);
        $ht = new HashTable();
        foreach ($hostMatches as $key => $match) {
            $slot = self::matchEntryToVariable($match, $offsetCapture, $unmatchedNull);
            if (\is_string($key)) {
                $ht->add($key, $slot);
            } else {
                $ht->updateIndex((int) $key, $slot);
            }
        }

        return $ht;
    }

    /**
     * @param array<int, mixed> $allMatches preg_match_all host shape
     */
    public static function hostMatchAllToHashTable(array $allMatches, int $flags): HashTable
    {
        $offsetCapture = 0 !== ($flags & StdlibConstants::PREG_OFFSET_CAPTURE);
        $setOrder = 0 !== ($flags & StdlibConstants::PREG_SET_ORDER);
        $ht = new HashTable();

        if ($setOrder) {
            foreach ($allMatches as $one) {
                $row = new HashTable();
                foreach ($one as $key => $match) {
                    $slot = self::matchEntryToVariable($match, $offsetCapture, false);
                    if (\is_string($key)) {
                        $row->add($key, $slot);
                    } else {
                        $row->updateIndex((int) $key, $slot);
                    }
                }
                $wrap = new Variable();
                $wrap->array($row);
                $ht->append($wrap);
            }

            return $ht;
        }

        foreach ($allMatches as $group => $entries) {
            $groupHt = new HashTable();
            foreach ($entries as $entry) {
                $groupHt->append(self::matchEntryToVariable($entry, $offsetCapture, false));
            }
            $wrap = new Variable();
            $wrap->array($groupHt);
            $ht->updateIndex((int) $group, $wrap);
        }

        return $ht;
    }

    private static function matchEntryToVariable(mixed $match, bool $offsetCapture, bool $unmatchedNull): Variable
    {
        $var = new Variable();
        if ($offsetCapture) {
            if (!\is_array($match) || !\array_key_exists(0, $match) || !\array_key_exists(1, $match)) {
                throw new \LogicException('preg match offset capture shape invalid in this compiler build');
            }
            $pair = new HashTable();
            if (null === $match[0]) {
                $nullVar = new Variable();
                $nullVar->null();
                $pair->append($nullVar);
            } else {
                $str = new Variable();
                $str->string((string) $match[0]);
                $pair->append($str);
            }
            $off = new Variable();
            $off->int((int) $match[1]);
            $pair->append($off);
            $var->array($pair);

            return $var;
        }

        if (null === $match) {
            if (!$unmatchedNull) {
                $var->string('');

                return $var;
            }
            $var->null();

            return $var;
        }

        $var->string((string) $match);

        return $var;
    }
}
