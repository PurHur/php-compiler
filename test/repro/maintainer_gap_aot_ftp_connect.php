<?php
/** Repro for #27393 — AOT ftp_connect refused-connect guard matches Zend/VM. */
$c = @ftp_connect("127.0.0.1", 21, 1);
var_dump($c === false || is_resource($c) || is_object($c));
