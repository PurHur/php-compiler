<?php
error_reporting(E_ALL);
echo "x=$missing\n";
$s = "a$missing b";
$s = "a{$missing} b";
echo "${missing}\n";
