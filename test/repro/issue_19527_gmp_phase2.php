<?php
foreach (["gmp_pow","gmp_mod","gmp_div_q","gmp_abs","gmp_and","gmp_intval"] as $f) {
  echo $f, "=", function_exists($f) ? "yes" : "no", "\n";
}
if (function_exists("gmp_init") && function_exists("gmp_pow")) {
  echo gmp_strval(gmp_pow(2, 10)), "\n";
}
