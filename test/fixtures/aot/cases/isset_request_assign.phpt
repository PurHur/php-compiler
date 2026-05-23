--TEST--
AOT: isset then assign $_REQUEST without include (#764)
--ENV--
QUERY_STRING=name=Dev
--FILE--
<?php
declare(strict_types=1);
$guestName = 'World';
if (isset($_REQUEST['name'])) {
    $guestName = $_REQUEST['name'];
}
echo $guestName, "\n";
--EXPECT--
Dev
