<?php
// #27662 — AOT pack/unpack must not OOM at compile
$b = pack("n", 258);
echo bin2hex($b), "\n";
$a = unpack("nval", $b);
echo $a["val"], "\n";
