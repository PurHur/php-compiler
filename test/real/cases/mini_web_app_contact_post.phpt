--TEST--
mini_web_app: contact form POST via PATH_INFO
--ENV--
REQUEST_METHOD=POST
SCRIPT_NAME=/index.php
PATH_INFO=/contact
CONTENT_TYPE=application/x-www-form-urlencoded
--POST--
name=PostDev
--RUNFILE--
../../../examples/003-MiniWebApp/public/index.php
--EXPECTREGEX--
Thank you, PostDev
