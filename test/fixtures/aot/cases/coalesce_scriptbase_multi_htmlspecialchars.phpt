--TEST--
AOT: repeated htmlspecialchars on ?? SCRIPT_NAME after inherited locals (MiniWebApp nav, #20507)
--ENV--
SCRIPT_NAME=/index.php
--RUNFILE--
coalesce_scriptbase_multi_htmlspecialchars/entry.php
--EXPECT--
<title>Home — MiniWebApp</title>
<a href="/index.php">Home</a>
<a href="/index.php/hello">Hello</a>
<a href="/index.php/contact">Contact</a>
