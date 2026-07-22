<?php
/** Repro #22372 — array_uasort/array_uksort phantoms: not in php-src ext/standard/array.c. */
echo function_exists('array_uasort') ? "uasort:exists\n" : "uasort:missing\n";
echo function_exists('array_uksort') ? "uksort:exists\n" : "uksort:missing\n";
