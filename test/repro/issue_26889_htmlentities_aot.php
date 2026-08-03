<?php
/** Issue #26889 — thin AOT htmlentities() must print entities (not segfault). */
echo htmlentities('<x>&'), "\n";
echo htmlentities('<€>'), "\n";
$s = '<tag>&';
echo htmlentities($s), "\n";
