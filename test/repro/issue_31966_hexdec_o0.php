<?php
// Repro #31966 — hexdec/octdec/bindec must match Zend under HELPER_RUNTIME_O=0 (NestedJIT inline).
echo dechex(10), "\n";
echo decoct(10), "\n";
echo decbin(10), "\n";
echo octdec('12'), "\n";
echo hexdec('ff'), "\n";
echo bindec('1010'), "\n";
