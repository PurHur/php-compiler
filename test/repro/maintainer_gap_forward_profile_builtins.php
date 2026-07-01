<?php
// Issue #14518 — str_increment/str_decrement/json_validate on 8.4.0-dev forward profile.
echo 'json_validate exists='.(function_exists('json_validate') ? 'yes' : 'no')."\n";
echo 'str_increment exists='.(function_exists('str_increment') ? 'yes' : 'no')."\n";
echo 'str_decrement exists='.(function_exists('str_decrement') ? 'yes' : 'no')."\n";
echo 'array_find exists='.(function_exists('array_find') ? 'yes' : 'no')."\n";
echo 'array_any exists='.(function_exists('array_any') ? 'yes' : 'no')."\n";
echo 'VERSION='.PHP_VERSION."\n";
if (function_exists('str_increment')) {
    echo 'str_increment(a)='.str_increment('a')."\n";
}
if (function_exists('json_validate')) {
    echo 'json_validate({})='.(json_validate('{}') ? 'true' : 'false')."\n";
}
