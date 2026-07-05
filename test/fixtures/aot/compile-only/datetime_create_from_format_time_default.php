<?php
// AOT compile-only (#16383): date-only createFromFormat() uses current time for unparsed fields.
$dt = DateTime::createFromFormat('Y-m-d', '2020-02-30');
var_export($dt !== false);
$partial = DateTime::createFromFormat('Y-m-d H', '2020-01-01 14');
var_export($partial !== false);
