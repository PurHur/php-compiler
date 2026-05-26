--TEST--
stdlib htmlspecialchars_decode() (#2454)
--FILE--
<?php
$s = '<script>alert(1)</script>';
echo htmlspecialchars_decode(htmlspecialchars($s)), "\n";
echo htmlspecialchars_decode('&lt;&gt;&amp;&quot;&#039;'), "\n";
echo htmlspecialchars_decode('plain'), "\n";
echo htmlspecialchars_decode(''), "\n";
--EXPECT--
<script>alert(1)</script>
<>&"'
plain

