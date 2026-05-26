--TEST--
stdlib htmlspecialchars_decode() (#2454)
--FILE--
<?php
echo htmlspecialchars_decode(''), "\n";
echo htmlspecialchars_decode('&lt;script&gt;alert(1)&lt;/script&gt;'), "\n";
echo htmlspecialchars_decode('Tom &amp; Jerry'), "\n";
echo htmlspecialchars_decode('&quot;quoted&quot;'), "\n";
echo htmlspecialchars_decode(htmlspecialchars('<a>&"\'</a>')), "\n";
echo htmlspecialchars_decode('foo & bar &amp; baz'), "\n";
--EXPECT--

<script>alert(1)</script>
Tom & Jerry
"quoted"
<a>&"'</a>
foo & bar & baz
