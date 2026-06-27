<?php
/**
 * Repro #12744 — getrusage() ru_maxrss must be non-zero for live CLI process.
 */
var_export(getrusage()['ru_maxrss'] > 0);
