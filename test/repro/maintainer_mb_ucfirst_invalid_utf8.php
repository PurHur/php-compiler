<?php
/** #28629 — mb_ucfirst/mb_lcfirst keep illegal lead when first-unit convert is a no-op. */
$s = "\xE9cole";
echo "out_hex:", bin2hex(mb_ucfirst($s, "UTF-8")), "\n";
echo "lc_hex:", bin2hex(mb_lcfirst($s, "UTF-8")), "\n";
$s2 = "a\xE9b";
echo "aEb_uc:", bin2hex(mb_ucfirst($s2, "UTF-8")), "\n";
echo "aEb_lc:", bin2hex(mb_lcfirst($s2, "UTF-8")), "\n";
