--TEST--
AOT: MiniWebApp dispatch POST contact with nested switch (#764) @group miniwebapp-bisect
--ENV--
REQUEST_METHOD=POST
QUERY_STRING=route=contact
REQUEST_BODY=name=PostDev
SCRIPT_NAME=/index.php
--RUNFILE--
miniwebapp_dispatch_post/entry.php
--EXPECTREGEX--
Thank you, PostDev
