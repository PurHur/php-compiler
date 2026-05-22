--TEST--
Web: re-read $_GET after assignment in same request (issue #645, #68)
--ENV--
QUERY_STRING=seed=1
--FILE--
<?php
echo $_GET['seed'], "\n";
$_GET['seed'] = 'mutated';
echo $_GET['seed'], "\n";
$key = 'seed';
echo $_GET[$key], "\n";
--EXPECT--
1
mutated
mutated
