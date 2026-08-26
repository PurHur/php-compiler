<?php
// #35045 — AOT NestedJIT stripslashes must strip (strlen bounds, not isset($s[$i+1]))
echo stripslashes('a\\b\\c'), PHP_EOL;
echo stripslashes("O\\'Reilly"), PHP_EOL;
echo bin2hex(stripslashes('a\\0b')), PHP_EOL;
echo stripslashes('noop'), PHP_EOL;
echo stripslashes(''), PHP_EOL;
