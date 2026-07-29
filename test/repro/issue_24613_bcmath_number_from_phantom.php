<?php
/** Repro #24613 — BcMath\Number::from() phantom under PROFILE=8.4; php-src has no from(). */
use BcMath\Number;

echo method_exists(Number::class, 'from') ? "fail\n" : "ok\n";
