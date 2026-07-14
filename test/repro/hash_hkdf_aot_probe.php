<?php
$key = hash_hkdf('sha256', 'key', 16, 'info', 'salt');
echo strlen($key), "\n";
echo bin2hex($key), "\n";
