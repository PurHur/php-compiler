--TEST--
AOT: runtime unserialize DateTime*/DateTimeZone Zend wire (#34599 / peer #34594)
--FILE--
<?php
require __DIR__ . '/../../../repro/issue_34599_unserialize_date_wire_aot.php';
--EXPECT--
DT:2020-01-02
DTI:2020-01-02
TZ:Europe/Berlin
FOLD:2020-01-02
