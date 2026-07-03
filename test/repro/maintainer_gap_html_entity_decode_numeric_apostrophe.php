<?php
// Issue #15275 — html_entity_decode() default decodes numeric &#039; to apostrophe.
$out = html_entity_decode('&lt;&#039;&gt;');
if ($out === "<'>") {
    echo "ok\n";
    exit(0);
}
echo "got: {$out}\n";
exit(1);
