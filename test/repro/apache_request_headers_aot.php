<?php
echo function_exists('apache_request_headers') ? 'yes' : 'no', "\n";
$apache = apache_request_headers();
$all = getallheaders();
echo $apache === $all ? 'same' : 'diff', "\n";
