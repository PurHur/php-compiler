<?php
mb_ereg_search_init('hello world', 'world');
$r = mb_ereg_search_pos();
echo is_array($r) ? ($r[0].','.$r[1]) : var_export($r, true);
echo "\n";
mb_ereg_search_init('hello world', '(wo)(rld)');
$r = mb_ereg_search_regs();
if (is_array($r)) {
    echo $r[0], '|', $r[1], '|', $r[2], "\n";
} else {
    echo 'false', "\n";
}
echo mb_ereg_search_getpos(), "\n";
$g = mb_ereg_search_getregs();
if (is_array($g)) {
    echo $g[0], '|', $g[1], '|', $g[2], "\n";
} else {
    echo 'false', "\n";
}
mb_ereg_search_init('abcdef', 'c');
mb_ereg_search_setpos(2);
$r = mb_ereg_search_pos();
echo is_array($r) ? ($r[0].','.$r[1]) : 'false';
echo "\n";
