<?php
/** Repro for #34674 — boxed bool ⊕ float must coerce true→1.0 (zend_operators). */
function box($x) {
    return $x;
}
var_dump(box(true) + box(1.5));
var_dump(box(false) + box(2.5));
var_dump(box(true) * box(3.0));
var_dump(box(true) / box(2.0));
