<?php

// #31159 — try/catch/else is not php-src syntax (Zend/zend_language_parser.y).
// Zend: Parse error: syntax error, unexpected token "else"
// Run: php bin/vm.php test/repro/issue_31159_try_catch_else.php
//      PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_31159_try_catch_else.php

try { echo "t"; } catch (Exception $e) { echo "c"; } else { echo "e"; }
echo "\n";
