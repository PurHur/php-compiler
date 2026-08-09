<?php
// #29247 — literal array append must compile-fatal (Zend write context).
[1, 2][] = 3;
echo "append_ok\n";
