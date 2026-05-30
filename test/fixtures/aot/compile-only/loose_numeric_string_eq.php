<?php
// Compile-only (#3644); AOT native run does not yet match Zend loose compare.
echo (0 == 'a') ? "1\n" : "0\n";
echo (0 == '0') ? "1\n" : "0\n";
