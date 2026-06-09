<?php
echo function_exists('mb_convert_case') ? "yes\n" : "no\n";
echo mb_convert_case('hello', MB_CASE_UPPER, 'UTF-8'), "\n";
echo mb_convert_case('HELLO', MB_CASE_LOWER), "\n";
echo mb_convert_case('hello world', MB_CASE_TITLE, 'UTF-8'), "\n";
