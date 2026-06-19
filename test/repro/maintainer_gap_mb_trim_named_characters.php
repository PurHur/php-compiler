<?php
// Issue #9839 — mb_trim()/mb_ltrim()/mb_rtrim() named parameters (php-src ext/mbstring/mbstring.c)

$s = '--héllo--';
var_dump(mb_trim($s, '-'));
var_dump(mb_trim($s, characters: '-'));
var_dump(mb_ltrim($s, characters: '-'));
var_dump(mb_rtrim($s, characters: '-'));
var_dump(mb_trim($s, encoding: 'UTF-8'));
