<?php
// Issue #26870 — AOT mb_str_split must match Zend/VM/JIT.
echo implode('-', mb_str_split('aéi', 1)), "\n";
