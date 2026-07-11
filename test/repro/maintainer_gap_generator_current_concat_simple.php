<?php
function g() { yield 10; yield 20; yield 30; }
$g = g();
$g->next();
$g->next();
$concat = 'val=' . var_export($g->current(), true);
echo $concat, "\n";
