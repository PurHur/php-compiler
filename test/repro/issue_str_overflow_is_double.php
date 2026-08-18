<?php
/**
 * #32432 — overflow numeric-string ⊙ int is IS_DOUBLE (zend _is_numeric_string_ex).
 * Leftover of #31964 / #32426: strtol clamp then +0 stays int.
 */
var_dump("9223372036854775808" + 0);
var_dump(0 + "9223372036854775808");
var_dump("9223372036854775808" + "0");
var_dump("10" + 3);
