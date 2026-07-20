<?php
// AOT compile-only: assert_options() native lowering (#3316, #21528).
// Cast makes the honest int get visible under AOT echo-of-value-box (#21528).
echo (string) assert_options(1), "\n";
assert_options(1, 0);
assert(false);
echo "ok\n";
