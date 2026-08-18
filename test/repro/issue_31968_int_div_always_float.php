<?php
/**
 * #31968 remaining group 2 — PHP `/` is always float (zend_div).
 * AOT native-long `/` used LLVM sdiv and printed int(3) for 7/2.
 */
var_dump(7 / 2);
var_dump(5 / 2);
