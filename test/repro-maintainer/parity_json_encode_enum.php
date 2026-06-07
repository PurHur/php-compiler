<?php

enum UnitEnum { case A; }
enum BackedString: string { case X = 'x'; }
enum BackedInt: int { case One = 1; }

echo 'unit: ', json_encode(UnitEnum::A), "\n";
echo 'backed_string: ', json_encode(BackedString::X), "\n";
echo 'backed_int: ', json_encode(BackedInt::One), "\n";
