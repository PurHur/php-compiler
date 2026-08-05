<?php
// Repro #27059 — AOT strcoll must match Zend/VM/JIT comparison signs (not always 0).
echo strcoll('a', 'b'), PHP_EOL;
echo strcoll('b', 'a'), PHP_EOL;
echo strcoll('a', 'a'), PHP_EOL;
