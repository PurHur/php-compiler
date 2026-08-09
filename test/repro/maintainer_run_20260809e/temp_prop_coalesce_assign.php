<?php
// #29247 — ??= on new temporary property must compile-fatal (Zend write context).
(new stdClass)->x ??= 1;
echo "coal_ok\n";
