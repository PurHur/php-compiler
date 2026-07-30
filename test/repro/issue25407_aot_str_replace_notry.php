<?php
/**
 * Issue #25407 — AOT uncaught path: native fatal ArgumentCountError
 * (try/catch ArgumentCountError still CFGs under AOT — same as phpversion_argument_count).
 */
str_replace('a', 'b');
echo "reached\n";
