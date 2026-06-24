<?php
// Zend: no E_WARNING when $hosts is undeclared; by-ref OUT binds silently (#11182).
getmxrr('example.com', $hosts);
echo is_array($hosts) ? "hosts-array\n" : "hosts-not-array\n";
echo count($hosts) >= 1 ? "hosts-nonempty\n" : "hosts-empty\n";
