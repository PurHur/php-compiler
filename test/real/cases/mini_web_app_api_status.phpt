--TEST--
mini_web_app: API status JSON via PATH_INFO
--ENV--
REQUEST_METHOD=GET
SCRIPT_NAME=/index.php
PATH_INFO=/api/status
--RUNFILE--
../../../examples/003-MiniWebApp/public/index.php
--EXPECTREGEX--
"ok":true
