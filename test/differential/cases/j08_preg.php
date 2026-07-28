<?php
// #24115: preg_match() SEGFAULTED (with and without the $matches out-param) and preg_replace()
// returned empty under AOT. Fixed in #24146; kept as a guard.
//
// The corpus had NO preg_* case at all before this batch, which is why a segfault in the most-used
// PCRE entry point was invisible to every gate in the project.
var_dump(preg_match('/\d+/', 'ab 12 cd'));
preg_match('/(\d+)/', 'ab 12 cd', $m);
echo $m[1], "\n";
echo preg_replace('/\s+/', '_', "a  b"), "\n";
