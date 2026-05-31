<?php
// Legacy compile-only probe; native execute covered by test/fixtures/aot/cases/loose_scientific_string_eq.phpt (#3658).
echo (0 == '0e123') ? "0\n" : "1\n";
echo (0 == '0') ? "1\n" : "0\n";
echo (1 == '1abc') ? "1\n" : "0\n";
