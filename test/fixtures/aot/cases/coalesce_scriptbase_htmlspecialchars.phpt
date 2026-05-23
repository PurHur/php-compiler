--TEST--
AOT: htmlspecialchars on ?? superglobal assign in included template (#764, #866)
--ENV--
SCRIPT_NAME=/index.php
--RUNFILE--
coalesce_scriptbase_htmlspecialchars/entry.php
--ENV--
SCRIPT_NAME=/index.php
--EXPECT--
/index.php<h1>ok</h1>
