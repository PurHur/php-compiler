<?php
/**
 * Issue #27564 — AOT preg_quote must match Zend under default helper-runtime cache.
 *
 * Prior #26827 fixed SIGSEGV; residual was silent empty string on cache hit (O=0 OK).
 *
 * VM:  php bin/vm.php test/repro/issue_27564_preg_quote_aot.php
 * JIT: php bin/jit.php test/repro/issue_27564_preg_quote_aot.php
 * AOT: php bin/compile.php -o /tmp/i27564 test/repro/issue_27564_preg_quote_aot.php && /tmp/i27564
 */
echo preg_quote('a.b*c', '/'), "\n";
echo preg_quote('a.b'), "\n";
echo preg_quote("a\0b"), "\n";
