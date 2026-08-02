<?php
/**
 * Issue #26827 — AOT preg_quote must match Zend (no SIGSEGV).
 *
 * VM:  php bin/vm.php test/repro/issue_26827_preg_quote_aot.php
 * JIT: php bin/jit.php test/repro/issue_26827_preg_quote_aot.php
 * AOT: php bin/compile.php -o /tmp/i26827 test/repro/issue_26827_preg_quote_aot.php && /tmp/i26827
 */
echo preg_quote('a.b*c', '/'), "\n";
echo preg_quote('a.b'), "\n";
echo preg_quote("a\0b"), "\n";
