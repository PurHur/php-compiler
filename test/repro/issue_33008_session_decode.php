<?php
// #33008: thin AOT session_decode after LLVM wire parse (peer #33005 encode).
session_start();
$ok = session_decode('a|i:1;');
var_export($ok);
echo '|';
echo isset($_SESSION['a']) ? 'has' : 'no';
echo PHP_EOL;
