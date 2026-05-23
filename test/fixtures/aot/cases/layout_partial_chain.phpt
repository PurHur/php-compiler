--TEST--
AOT: layout partial chain — conditional includes in layout.php (issues #807, #784, #764) @group miniwebapp-bisect
--ENV--
SCRIPT_NAME=/index.php
--RUNFILE--
layout_partial_chain/entry.php
--EXPECT--
Home — MiniWebApp
HomePartial
