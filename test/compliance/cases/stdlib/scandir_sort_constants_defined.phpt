--TEST--
SCANDIR_SORT_* constants — defined()/constant() parity (#14583, ext/standard/dir.c)
--FILE--
<?php
echo defined('SCANDIR_SORT_ASCENDING') && SCANDIR_SORT_ASCENDING === 0 ? "asc\n" : "asc_bad\n";
echo defined('SCANDIR_SORT_DESCENDING') && SCANDIR_SORT_DESCENDING === 1 ? "desc\n" : "desc_bad\n";
echo defined('SCANDIR_SORT_NONE') && SCANDIR_SORT_NONE === 2 ? "none\n" : "none_bad\n";
echo constant('SCANDIR_SORT_ASCENDING') === 0 ? "fetch_ok\n" : "fetch_bad\n";
--EXPECT--
asc
desc
none
fetch_ok
