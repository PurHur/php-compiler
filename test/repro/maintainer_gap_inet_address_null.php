<?php
// #19053 — inet_ntop(null)/inet_pton(null) return false, not TypeError (ext/standard/basic_functions.c).
$ntop = inet_ntop(null);
$pton = inet_pton(null);
echo ($ntop === false && $pton === false) ? "ok\n" : "fail\n";
