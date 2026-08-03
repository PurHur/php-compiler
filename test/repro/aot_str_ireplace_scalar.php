<?php
// Repro #27079 — AOT str_ireplace must match Zend (was empty under NestedJIT).
echo str_ireplace('A', 'b', 'Aaa'), PHP_EOL;
