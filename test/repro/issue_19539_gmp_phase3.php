<?php
foreach (["gmp_powm","gmp_fact","gmp_gcd","gmp_sqrt"] as $f) {
  echo $f, "=", function_exists($f) ? "yes" : "no", "\n";
}
if (function_exists("gmp_powm")) {
  echo gmp_strval(gmp_powm(2, 10, 1000)), "\n";
}
