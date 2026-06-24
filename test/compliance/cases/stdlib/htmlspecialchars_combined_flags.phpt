--TEST--
stdlib htmlspecialchars() combined ENT_* flags (#11027, ext/standard/html.c)
--FILE--
<?php
echo htmlspecialchars('<a>', ENT_COMPAT | ENT_HTML401), "\n";
echo htmlspecialchars('<a>', ENT_COMPAT | ENT_QUOTES), "\n";
echo htmlspecialchars("'", ENT_QUOTES | ENT_HTML5), "\n";
--EXPECT--
&lt;a&gt;
&lt;a&gt;
&apos;
