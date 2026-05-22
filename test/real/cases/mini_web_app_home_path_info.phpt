--TEST--
mini_web_app: home route via PATH_INFO
--ENV--
REQUEST_METHOD=GET
SCRIPT_NAME=/index.php
PATH_INFO=/home
--RUNFILE--
../../../examples/003-MiniWebApp/public/index.php
--EXPECTREGEX--
MiniWebApp
