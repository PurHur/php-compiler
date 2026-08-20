--TEST--
AOT: ParentNode::append/prepend multi-arg refreshes held childNodes (#32838)
--FILE--
<?php
require dirname(__DIR__, 3).'/repro/issue_32838_dom_parentnode_append_multi_live_held.php';
--EXPECT--
append_held_len=3
append_held0=a
append_held1=b
append_held2=c
append_refetch_len=3
prepend_held_len=3
prepend_held0=b
prepend_held1=c
prepend_held2=a
prepend_refetch_len=3
