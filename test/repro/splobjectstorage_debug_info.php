<?php
// Repro #19826 — SplObjectStorage var_dump must show private storage {obj,inf}.
$s = new SplObjectStorage();
$o = new stdClass();
$s[$o] = 'v';
var_dump($s);
