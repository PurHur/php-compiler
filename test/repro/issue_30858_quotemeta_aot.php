<?php
// Repro #30858: AOT quotemeta() must not SIGSEGV (VM/JIT match Zend).
echo quotemeta("a.b*c"), "\n";
