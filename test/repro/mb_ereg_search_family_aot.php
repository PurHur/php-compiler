<?php
/**
 * #34424 — mb_ereg_search_{pos,regs,getpos,getregs,setpos} AOT folds.
 * Avoid var_export(array) — thin AOT aborts (#26855).
 */
mb_ereg_search_init('hello world', 'world');
$pos = mb_ereg_search_pos();
echo is_array($pos) ? ($pos[0].','.$pos[1]) : 'false', "\n";
echo mb_ereg_search_getpos(), "\n";

mb_ereg_search_init('abc123def', '[0-9]+');
$regs = mb_ereg_search_regs();
echo is_array($regs) ? $regs[0] : 'false', "\n";
$g = mb_ereg_search_getregs();
echo is_array($g) ? $g[0] : 'false', "\n";
echo mb_ereg_search_getpos(), "\n";

mb_ereg_search_init('hello', '.');
mb_ereg_search_setpos(-1);
echo mb_ereg_search_getpos(), "\n";
