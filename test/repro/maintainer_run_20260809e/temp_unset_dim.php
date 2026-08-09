<?php
// #29247 — unset on literal array dim must compile-fatal (Zend write context).
unset([1, 2][0]);
echo "unset_ok\n";
