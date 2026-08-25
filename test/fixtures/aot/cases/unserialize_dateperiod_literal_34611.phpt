--TEST--
AOT: literal DatePeriod unserialize foreach without prior new (#34611 / re-#34608)
--FILE--
<?php
require __DIR__ . '/../../../repro/issue_34611_dateperiod_literal_unserialize_aot.php';
--EXPECT--
2020-01-01
2020-01-02
2020-01-03
2020-01-04
