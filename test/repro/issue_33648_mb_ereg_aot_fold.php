<?php
declare(strict_types=1);
var_export(mb_ereg('^[a-z]+$', 'hello'));
echo "\n";
var_export(mb_ereg('^[a-z]+$', 'HELLO'));
echo "\n";
var_export(mb_eregi('HELLO', 'hello'));
echo "\n";
