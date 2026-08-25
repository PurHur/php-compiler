<?php
// AOT: exit(null) literal must not SIGSEGV the compiler (#34761).
// PHP 8.2: empty output, status 0.
exit(null);
