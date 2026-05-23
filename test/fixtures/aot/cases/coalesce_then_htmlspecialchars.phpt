--TEST--
AOT: htmlspecialchars on inherited local after superglobal ?? (#866, #764)
--ENV--
SCRIPT_NAME=/index.php
--RUNFILE--
coalesce_then_inherited_local/entry_htmlspecialchars.php
--ENV--
SCRIPT_NAME=/index.php
--EXPECT--
Home
