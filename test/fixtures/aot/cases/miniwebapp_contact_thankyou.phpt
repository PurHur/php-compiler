--TEST--
AOT: MiniWebApp POST contact thank-you via REQUEST_BODY (#764) @group miniwebapp-bisect
--ENV--
REQUEST_METHOD=POST
QUERY_STRING=route=contact
REQUEST_BODY=name=PostDev
--RUNFILE--
miniwebapp_contact_thankyou/entry.php
--EXPECTREGEX--
Thank you, PostDev
