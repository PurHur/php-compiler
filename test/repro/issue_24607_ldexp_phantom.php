<?php
/** Repro #24607 — ldexp() phantom; php-src math.stub.php has no userland ldexp. */
echo function_exists('ldexp') ? "fail\n" : "ok\n";
