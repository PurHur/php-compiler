<?php
declare(strict_types=1);

enum Es: string { case A = 'a'; case B = 'b'; }
enum U { case X; }

$re = new ReflectionEnum(Es::class);
var_export($re->hasCase('A'));
echo "\n";
var_export($re->hasCase('Z'));
echo "\n";
var_export($re->isBacked());
echo "\n";
$c = $re->getCase('A');
echo $c->name, "\n";

$ru = new ReflectionEnum(U::class);
var_export($ru->isBacked());
echo "\n";
