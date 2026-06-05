<?php
// Compile-only (#6083): linkinfo() must lower lstat(2) st_dev for AOT.
$link = 'test/compliance/cases/stdlib/is_link_fixture/link';
$i1 = linkinfo($link);
$i2 = linkinfo($link);
echo ($i1 > 0 && $i1 === $i2) ? 'ok' : 'fail', "\n";
