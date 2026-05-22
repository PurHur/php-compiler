--TEST--
mini_web_app: hello route via PATH_INFO and QUERY_STRING
--ENV--
REQUEST_METHOD=GET
SCRIPT_NAME=/index.php
PATH_INFO=/hello
QUERY_STRING=name=Dev
--RUNFILE--
../../../examples/003-MiniWebApp/public/index.php
--EXPECTREGEX--
Hello Dev
