<?php
// #22646 — AOT string offset read (script-global value box + bounds).
$s = 'AOT';
echo $s[0], '|', strlen($s), "\n";
echo $s[-1], "\n";
echo strlen($s[99]), "\n";
