<?php
// HashTable rehash packs IS_UNDEF holes (zend_hash_rehash) — #36380 Parsedown lists.
function mutate(array $B): array {
    unset($B['li']);
    $B['indent'] = 0;
    $B['li'] = ['x' => 1];
    return $B;
}
$B = ['indent' => 0, 'a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5];
$B['li'] = ['v' => 1];
$B['element'] = ['elements' => []];
$B['element']['elements'][] = &$B['li'];
$B = mutate($B);
echo ($B['indent'] ?? 'MISSING'), "\n";
echo (array_key_exists('indent', $B) ? 'yes' : 'no'), "\n";
