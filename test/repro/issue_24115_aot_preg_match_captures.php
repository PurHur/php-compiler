<?php
// #24115 — AOT preg_match with captures (was segfault)
preg_match('/(\d+)/', 'ab 12 cd', $m);
echo $m[1], "\n";
