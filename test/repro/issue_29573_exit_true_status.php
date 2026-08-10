<?php
/** Repro #29573 — exit(true) → exit code 1, no stdout (PHP 8.4 function form). */
error_reporting(E_ALL);
exit(true);
