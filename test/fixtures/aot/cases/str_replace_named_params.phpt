--TEST--
AOT: str_replace()/str_ireplace() named search:/replace:/subject: arguments (#9648)
--FILE--
<?php
echo str_replace(search: 'a', replace: 'b', subject: 'aca'), "\n";
echo str_ireplace(search: 'A', replace: 'b', subject: 'AcA'), "\n";
--EXPECT--
bcb
bcb
