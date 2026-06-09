<?php
// Compile-only (#4193): soundex()/metaphone() string operand lowering for AOT.
echo soundex(123), "\n";
echo metaphone(true), "\n";
