<?php

declare(strict_types=1);

// php-src ext/standard/pack.c — repeated format embedded in name (issue #10413)
var_export(unpack('a2a2', 'abcd'));
echo "\n";
var_export(unpack('A2A2', 'abcd'));
echo "\n";
var_export(unpack('Z2Z2', "a\x00b\x00"));
echo "\n";
var_export(unpack('h2h2', 'abcd'));
echo "\n";
var_export(unpack('C2foo', 'AB'));
echo "\n";
