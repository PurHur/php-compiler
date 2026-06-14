<?php
declare(strict_types=1);

$r = sscanf('ff', '%x');
echo isset($r[0]) ? (string) $r[0] : 'null', "\n";

$r = sscanf('377', '%o');
echo isset($r[0]) ? (string) $r[0] : 'null', "\n";

$r = sscanf('-42', '%u');
echo isset($r[0]) ? $r[0] : 'null', "\n";
