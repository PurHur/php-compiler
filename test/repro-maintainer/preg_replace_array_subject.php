<?php
$subject = ['a1', 'b2'];
$result = preg_replace('/\d/', 'X', $subject);
echo $result[0], "\n";
echo $result[1], "\n";
