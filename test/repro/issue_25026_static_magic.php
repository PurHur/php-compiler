<?php
// #25026 — static __sleep/__wakeup/__invoke must be compile fatals (Zend/zend_compile.c).
class Sl { static function __sleep() { return []; } }
echo "accepted\n";
