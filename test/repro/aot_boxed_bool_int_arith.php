<?php
/** Repro for #34678 — boxed bool ⊕ int must coerce true→1 (zend_operators convert_to_long). */
function box($x) {
    return $x;
}
var_dump(box(true) + box(2));
var_dump(box(false) + box(2));
var_dump(box(true) * box(3));
var_dump(box(true) - box(1));
