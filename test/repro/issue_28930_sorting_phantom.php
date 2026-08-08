<?php
/**
 * Repro for #28930 — Sorting / SortDirection phantoms absent under PROFILE≥8.4.
 * php-src never ships these enums; sort order remains SORT_ASC / SORT_DESC ints.
 */
echo 'Sorting=', enum_exists('Sorting') ? 'Y' : 'N', "\n";
echo 'SortDirection=', enum_exists('SortDirection') ? 'Y' : 'N', "\n";
