<?php
// Issue #27385 — numfmt_create AOT must materialize NumberFormatter (re-#20754).
var_export(numfmt_create('en_US', NumberFormatter::DECIMAL) instanceof NumberFormatter);
echo PHP_EOL;
