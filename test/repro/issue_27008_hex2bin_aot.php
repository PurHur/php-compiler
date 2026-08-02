<?php
/** Repro #27008 — AOT hex2bin() must compile and print ab (php-src-strict). */
echo hex2bin('6162'), "\n";
