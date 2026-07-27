<?php
// @differential-skip-aot: print_r() JIT helper requires Runtime->vm from thin standalone init (#9190 / #23540)
// #23540: print_r aborted identically — the whole value-dumping family was affected.
print_r([1, 2, 3]);
echo "\n";
print_r(['a' => 1, 'b' => ['c' => 2]]);
echo "\n";
