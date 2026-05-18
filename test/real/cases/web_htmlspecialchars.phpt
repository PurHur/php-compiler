--TEST--
Web: escape user input for HTML output
--ENV--
QUERY_STRING=name=%3Cscript%3Ealert(1)%3C%2Fscript%3E
--FILE--
<?php
$name = $_GET['name'];
echo '<h1>Hello ', htmlspecialchars($name), "</h1>\n";
--EXPECT--
<h1>Hello &lt;script&gt;alert(1)&lt;/script&gt;</h1>
