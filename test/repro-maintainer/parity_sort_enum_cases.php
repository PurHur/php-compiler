<?php
enum EInt: int { case A = 1; case B = 2; }
enum EUnit { case A; case B; }

$a = [EInt::B, EInt::A];
sort($a);
var_export($a);

$b = [EUnit::B, EUnit::A];
sort($b);
var_export($b);

// Enum case array keys are illegal in Zend and VM (TypeError on literal construction).

