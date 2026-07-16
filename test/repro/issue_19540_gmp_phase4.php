<?php
foreach (["gmp_random_bits","gmp_random_range","gmp_import","gmp_export"] as $f) {
  echo $f, "=", function_exists($f) ? "yes" : "no", "\n";
}
echo gmp_strval(gmp_import("\0\1\2")), "\n";
