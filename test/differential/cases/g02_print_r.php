<?php
// #23540: print_r aborted identically — the whole value-dumping family was affected.
print_r([1, 2, 3]);
echo "\n";
print_r(['a' => 1, 'b' => ['c' => 2]]);
echo "\n";
