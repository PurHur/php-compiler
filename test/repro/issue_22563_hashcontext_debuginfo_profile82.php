<?php
// Repro #22563 — HashContext::__debugInfo withheld on PROFILE=8.2 (Zend 8.2 parity)
$h = hash_init('sha256');
echo '__debugInfo=', method_exists($h, '__debugInfo') ? '1' : '0', "\n";
echo 'gcm=', json_encode(get_class_methods($h)), "\n";
var_dump($h);
