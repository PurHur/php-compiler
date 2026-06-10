<?php
// Issue #7443 — number_format() Z_PARAM_STR coerces int separators (ext/standard/number_format.c).
echo number_format(1234.5, 2, '.', 0), "\n";
