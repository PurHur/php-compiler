<?php

/**
 * #26885 — get_debug_type() thin AOT must match Zend (array / null / stdClass).
 * Compile path previously segfaulted on boxed array (structGep on loadValue).
 */
echo get_debug_type([]), "\n";
echo get_debug_type(null), "\n";
echo get_debug_type(new stdClass), "\n";
