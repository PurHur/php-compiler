<?php
enum E: string { case A = 'a'; }
$c = E::A;
echo interface_exists('UnitEnum') ? '1' : '0', "\n";
echo interface_exists('BackedEnum') ? '1' : '0', "\n";
echo is_a($c, UnitEnum::class) ? '1' : '0', "\n";
