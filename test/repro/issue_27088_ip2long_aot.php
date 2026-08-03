<?php
// Repro #27088 — AOT ip2long/long2ip must compile and print Zend-matching values.
echo ip2long("127.0.0.1"), "\n";
echo long2ip(2130706433), "\n";
