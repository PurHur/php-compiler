<?php
$strong = false;
$b = openssl_random_pseudo_bytes(8, $strong);
echo strlen($b) . ',' . ($strong ? 'strong' : 'weak') . "\n";
