<?php
error_reporting(E_ALL);
$result = fpow(null, 1.0);
echo 'fpow(null)=', var_export($result, true), "\n";
