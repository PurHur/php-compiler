<?php
/** Repro #22249 — mysqli_real_query / mysqli::real_query registration. */
echo 'fn=', function_exists('mysqli_real_query') ? 'Y' : 'N', PHP_EOL;
echo 'oo=', method_exists('mysqli', 'real_query') ? 'Y' : 'N', PHP_EOL;
