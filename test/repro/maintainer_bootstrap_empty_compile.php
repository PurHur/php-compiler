<?php
// Repro for #6829: bootstrap compile must not fatal on empty()/!$var when php-cfg clears unary expr.
// Verification:
//   ./script/docker-exec.sh -- php bin/compile.php -l lib/VM.php
//   ./script/docker-exec.sh -- php bin/compile.php -l lib/Compiler.php
