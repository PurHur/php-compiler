<?php

declare(strict_types=1);

$s = "\xE9";
$encs = ['UTF-8', 'ISO-8859-1'];
echo var_export(mb_convert_encoding($s, 'UTF-8', $encs)), "\n";
