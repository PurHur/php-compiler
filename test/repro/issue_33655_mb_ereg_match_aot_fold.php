<?php
declare(strict_types=1);
var_export(mb_ereg_match('hello', 'hello'));
echo "\n";
var_export(mb_ereg_match('hello', 'xhello'));
echo "\n";
var_export(mb_ereg_match('[a-z]+', 'HELLO', 'i'));
echo "\n";
