<?php
// AOT: literal exit(null) must not SIGSEGV the compiler (#34764).
// PHP 8.2: silent status 0 (null literal is TYPE_VALUE + isNullConstant).
exit(null);
