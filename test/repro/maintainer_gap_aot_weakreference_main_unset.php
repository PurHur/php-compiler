<?php
// Repro for #27118 — WeakReference::get after unset($o) in {main} under AOT.
// Script globals are KIND_VALUE functionStaticGlobal boxes; unset must valueDelref.
// get() must return an owning copy so statement temps release cleanly before unset.
$o = new stdClass();
$w = WeakReference::create($o);
echo ($w->get() !== null) ? "live\n" : "dead\n";
unset($o);
echo ($w->get() === null) ? "dead\n" : "live\n";
