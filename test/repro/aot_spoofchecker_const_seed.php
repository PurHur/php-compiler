<?php
// #35396 — ClassConstFetch must seed Spoofchecker::* for thin AOT (peer #35389).
echo 'SINGLE_SCRIPT=', Spoofchecker::SINGLE_SCRIPT, "\n";
echo 'INVISIBLE=', Spoofchecker::INVISIBLE, "\n";
echo 'ALL_CHECKS=', Spoofchecker::ALL_CHECKS, "\n";
