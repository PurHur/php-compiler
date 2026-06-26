<?php
echo bin2hex(hash_hkdf('sha256', 'key', 8, '', 42)), "\n";
