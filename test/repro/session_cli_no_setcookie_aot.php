<?php
/**
 * #33445 — AOT CLI session_start must not print Set-Cookie (Zend/VM body only).
 * CGI/web sets REQUEST_METHOD and still needs the cookie line for #1891.
 */
session_start();
$_SESSION['a'] = 1;
echo $_SESSION['a'];
