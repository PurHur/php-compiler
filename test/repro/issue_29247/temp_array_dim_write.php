<?php
// #29247 — literal array dim write must compile-fatal (Zend write context).
[1, 2][0] = 9;
echo "dim_ok\n";
