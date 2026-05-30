<?php
// Compile-only (#3658); AOT native run does not yet match Zend loose compare.
echo (0 == '0e123') ? "0\n" : "1\n";
echo (0 == '0') ? "1\n" : "0\n";
echo (1 == '1abc') ? "1\n" : "0\n";
