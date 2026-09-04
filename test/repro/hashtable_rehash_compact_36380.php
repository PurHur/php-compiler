<?php
/**
 * unset() of a referenced string key on a full (size-8) HashTable must not
 * corrupt sibling keys when the next write triggers rehash (#36380).
 *
 * php-src: Zend/zend_hash.c zend_hash_rehash — packs IS_UNDEF before rebuild.
 */
function mutate(array $B): array
{
    unset($B['li']);
    $B['indent'] = 0;
    $B['li'] = ['x' => 1];
    $B['element']['elements'][] = &$B['li'];

    return $B;
}

$B = [
    'indent' => 0,
    'a' => 1,
    'b' => 2,
    'c' => 3,
    'd' => 4,
    'e' => 5,
];
$B['li'] = ['v' => 1];
$B['element'] = ['elements' => []];
$B['element']['elements'][] = &$B['li'];

$B = mutate($B);
$keys = array_keys($B);
$dup = count($keys) !== count(array_unique($keys));
echo 'indent=', var_export($B['indent'] ?? 'MISSING', true), "\n";
echo 'dup=', $dup ? '1' : '0', "\n";
echo 'keys=', implode(',', $keys), "\n";
