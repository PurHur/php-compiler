--TEST--
mini_web_app: contact POST rejects name over max length (issue #697)
--ENV--
REQUEST_METHOD=POST
SCRIPT_NAME=/index.php
PATH_INFO=/contact
CONTENT_TYPE=application/x-www-form-urlencoded
--POST--
name=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
--RUNFILE--
../../../examples/003-MiniWebApp/public/index.php
--EXPECT--
Invalid contact name
