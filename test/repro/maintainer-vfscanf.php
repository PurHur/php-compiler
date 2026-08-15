<?php
// Repro for #26758 — vfscanf() must not be a public builtin (php-src absent).
echo 'vfscanf: ', function_exists('vfscanf') ? 'yes' : 'no', "\n";
echo 'fscanf: ', function_exists('fscanf') ? 'yes' : 'no', "\n";
