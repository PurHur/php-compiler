--TEST--
AOT: header() with replace=false appends duplicate header names (issue #51)
--FILE--
<?php
header('X-One: first', false);
header('X-One: second', false);
echo header_list()[0], "\n";
echo header_list()[1], "\n";
echo "done\n";
--EXPECT--
X-One: first
X-One: second
done
