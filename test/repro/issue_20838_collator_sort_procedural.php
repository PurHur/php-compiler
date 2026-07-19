<?php

declare(strict_types=1);

// Issue #20838 — collator_sort / asort / sort_with_sort_keys procedurals.
$col = Collator::create('en_US');
$oop = ['c', 'a', 'b'];
$col->sort($oop);
echo 'oop='.implode(',', $oop)."\n";
foreach (['collator_compare', 'collator_sort', 'collator_asort', 'collator_sort_with_sort_keys'] as $f) {
    echo $f.'='.(function_exists($f) ? 'yes' : 'no')."\n";
}
if (function_exists('collator_sort')) {
    $arr = ['c', 'a', 'b'];
    collator_sort($col, $arr);
    echo 'sort='.implode(',', $arr)."\n";
    $assoc = ['x' => 'c', 'y' => 'a', 'z' => 'b'];
    collator_asort($col, $assoc);
    echo 'asort='.implode(',', $assoc).' keys='.implode(',', array_keys($assoc))."\n";
    $nums = ['10', '2', '1'];
    $col->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
    collator_sort_with_sort_keys($col, $nums);
    echo 'sortkeys='.implode(',', $nums)."\n";
}
