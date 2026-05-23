--TEST--
AOT: layout title-branch partial includes (issues #784, #846, #764) @group miniwebapp-bisect
--ENV--
SCRIPT_NAME=/index.php
--RUNFILE--
layout_title_branch/entry.php
--EXPECT--
Home — MiniWebApp
MiniWebApp
