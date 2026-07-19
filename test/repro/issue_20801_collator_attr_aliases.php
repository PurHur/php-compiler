<?php
// Repro #20801 — collator_* attribute/strength/sort_key/locale/error procedural aliases
$col = Collator::create('en_US');
foreach ([
    'collator_get_attribute',
    'collator_set_attribute',
    'collator_get_strength',
    'collator_set_strength',
    'collator_get_sort_key',
    'collator_get_locale',
    'collator_get_error_code',
    'collator_get_error_message',
] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}
$oop = $col->getAttribute(Collator::STRENGTH);
$proc = collator_get_attribute($col, Collator::STRENGTH);
echo 'match_attr=', (int) ($oop === $proc), ' val=', (int) $proc, "\n";
echo 'set_str=', (int) collator_set_strength($col, Collator::PRIMARY), "\n";
echo 'str=', (int) collator_get_strength($col), "\n";
echo 'attr=', (int) collator_get_attribute($col, Collator::STRENGTH), "\n";
$key = collator_get_sort_key($col, 'abc');
echo 'sortkey_nonempty=', (int) (is_string($key) && strlen($key) > 0), "\n";
echo 'locale=', collator_get_locale($col, 1), "\n";
echo 'err=', collator_get_error_code($col), ' msg=', collator_get_error_message($col), "\n";
