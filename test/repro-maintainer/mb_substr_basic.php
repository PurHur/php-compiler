<?php

declare(strict_types=1);

var_dump(function_exists('mb_substr'));
var_dump(function_exists('mb_strpos'));
echo mb_substr('café', 0, 2, 'UTF-8'), "\n";
var_dump(mb_strpos('αβγδ', 'γ', 0, 'UTF-8'));
echo mb_strtolower('HELLO'), "\n";
echo mb_strtoupper('hello'), "\n";
