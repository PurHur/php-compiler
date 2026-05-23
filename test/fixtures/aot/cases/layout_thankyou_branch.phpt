--TEST--
AOT: layout.php Thank you title-branch partial include (#764, #784) @group miniwebapp-bisect
--ENV--
SCRIPT_NAME=/index.php
--RUNFILE--
layout_thankyou_branch/entry.php
--EXPECTREGEX--
Thank you, PostDev
