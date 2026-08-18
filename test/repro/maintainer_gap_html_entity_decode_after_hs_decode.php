<?php
error_reporting(E_ALL);
var_dump(htmlspecialchars_decode('&quot;&amp;&lt;&#039;', ENT_QUOTES));
var_dump(html_entity_decode('&eacute;', ENT_QUOTES, 'UTF-8'));
