<?php

$s = "a\xFFb";
$sub = htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE);
$plain = htmlspecialchars($s, ENT_QUOTES);
$entSub = htmlentities($s, ENT_QUOTES | ENT_SUBSTITUTE);
$entPlain = htmlentities($s, ENT_QUOTES);
echo 'sub_hex=', bin2hex($sub), ' plain=', var_export($plain, true), "\n";
echo 'ent_sub_hex=', bin2hex($entSub), ' ent_plain=', var_export($entPlain, true), "\n";
