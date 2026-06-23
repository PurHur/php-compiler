<?php

declare(strict_types=1);

$subject = 'a,b,c';

var_export(preg_split('/,/', $subject, 0));
echo "\n";
var_export(preg_split('/,/', $subject, 1));
echo "\n";
var_export(preg_split('/,/', $subject, 2));
echo "\n";
