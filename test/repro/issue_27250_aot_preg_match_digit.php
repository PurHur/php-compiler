<?php
// #27250: AOT preg_match('/\d/', …) must return 1 (re-#26888 silent wrong-0).
var_export(preg_match('/\d/', 'a1'));
echo "\n";
