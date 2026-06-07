<?php
$x = (void)(1 + 2);
var_export($x);
echo "\n";
function side(): int { echo "side\n"; return 99; }
$y = (void)side();
var_export($y);
echo "\n";
#[\NoDiscard]
function f(): int { return 1; }
(void)f();
echo "ok\n";
