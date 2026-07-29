<?php
// #25023 — magic methods that must take zero parameters (Zend/zend_compile.c)
class W { function __wakeup($a = null) {} }
echo "accepted\n";
