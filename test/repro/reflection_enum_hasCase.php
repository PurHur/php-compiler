<?php
enum E: string { case A = 'a'; }
$r = new ReflectionEnum(E::class);
var_dump($r->hasCase('A'));
var_dump($r->hasCase('Z'));
