--TEST--
SimpleXML: simplexml_load_string/file(null) TypeError on 8.4 forward profile (#20352)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
load_null_typeerror_84.php
--EXPECT--
ok simplexml_load_string
ok simplexml_load_file
