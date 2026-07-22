<?php

/**
 * #21947 — thin AOT $_SESSION['z']=null must insert key (wire z|N;).
 * Run under CGI + PHP_COMPILER_SESSION_DIR for sess_* wire; VM path also OK.
 */
session_start();
$_SESSION['z'] = null;
echo 'akey=', array_key_exists('z', $_SESSION) ? '1' : '0', "\n";
session_write_close();
