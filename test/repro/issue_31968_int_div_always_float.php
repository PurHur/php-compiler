<?php
/**
 * #31968 — non-exact integer `/` yields float (zend_div). Exact quotients stay int (#35337).
 * AOT native-long `/` used LLVM sdiv and printed int(3) for 7/2 before #31968.
 */
var_dump(7 / 2);
var_dump(5 / 2);
