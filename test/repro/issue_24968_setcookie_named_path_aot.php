<?php
error_reporting(E_ALL);
ob_start();
echo setcookie(name: 'n', value: 'v', path: '/') ? 'setcookie:1' : 'setcookie:0', PHP_EOL;
echo setrawcookie(name: 'n', value: 'v', path: '/') ? 'setrawcookie:1' : 'setrawcookie:0', PHP_EOL;
