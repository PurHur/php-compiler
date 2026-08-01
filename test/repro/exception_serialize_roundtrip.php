<?php
// #26673 — unserialize(serialize(Exception)) must restore getMessage()/getCode()
$e = new Exception('hi', 7);
$u = unserialize(serialize($e));
echo $u->getMessage(), ':', $u->getCode(), "\n";
