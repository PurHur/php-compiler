<?php

interface I7309Aot { const X = 1; }
enum E7309Aot: string { case A = 'a'; case B = 'b'; }
$c = class_constants('I7309Aot');
echo ($c['X'] ?? '') === 1 ? '1' : '0';
$e = class_constants(E7309Aot::class);
echo isset($e['A'], $e['B']) && $e['A'] === E7309Aot::A && $e['B'] === E7309Aot::B ? '1' : '0';
