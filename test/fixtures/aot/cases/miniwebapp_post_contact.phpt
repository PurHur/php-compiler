--TEST--
AOT: MiniWebApp POST contact thank-you via $_REQUEST name (#485, #747)
--ENV--
REQUEST_METHOD=POST
QUERY_STRING=route=contact
--POST--
name=PostDev
--RUNFILE--
miniwebapp_post_contact/entry.php
--EXPECT--
Thank you, PostDev
