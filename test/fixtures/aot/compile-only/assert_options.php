<?php
// AOT compile-only: assert_options() native lowering (#3316).
echo assert_options(1), "\n";
assert_options(1, 0);
assert(false);
echo "ok\n";
