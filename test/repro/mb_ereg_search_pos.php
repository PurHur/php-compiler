<?php
/**
 * #34424 — mb_ereg_search_pos / getpos AOT fold after init.
 * Avoid var_export(array) — thin AOT aborts (#26855).
 */
mb_ereg_search_init('hello world', 'world');
$pos = mb_ereg_search_pos();
echo is_array($pos) ? ($pos[0].','.$pos[1]) : 'false', "\n";
echo mb_ereg_search_getpos(), "\n";
