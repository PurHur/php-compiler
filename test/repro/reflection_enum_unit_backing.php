<?php
enum U { case A; case B; }
$r = new ReflectionEnum(U::class);
var_export($r->getBackingType());
echo "\n";
