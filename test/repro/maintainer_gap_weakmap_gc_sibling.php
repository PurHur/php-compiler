<?php
$m = new WeakMap();
$keep = new stdClass();
$drop = new stdClass();
$m[$keep] = 'keep';
$m[$drop] = 'drop';
unset($drop);
echo 'pre_gc=', count($m), "\n";
gc_collect_cycles();
echo 'post_gc=', count($m), ' isset_keep=', isset($m[$keep]) ? '1' : '0', "\n";
