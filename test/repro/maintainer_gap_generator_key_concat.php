<?php
function g() { yield 10; yield 20; yield 30; }
$g = g();
$g->next();
$concat = 'key=' . var_export($g->key(), true);
echo $concat, "\n";
