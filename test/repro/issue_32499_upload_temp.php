<?php
// Repro #32499 — leftover Type.php always-on upload-temp ABIs dropped.
// is_uploaded_file()/move_uploaded_file() AOT must still compile
// (php-src ext/standard/basic_functions.c).
echo is_uploaded_file('/tmp/nope') ? "uploaded\n" : "not_uploaded\n";
echo move_uploaded_file('/tmp/nope', '/tmp/nope2') ? "moved\n" : "not_moved\n";
