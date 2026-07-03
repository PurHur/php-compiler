<?php
// Issue #15272 — htmlentities() default flags encode apostrophe as &#039;.
$out = htmlentities("<a&'>");
if ($out === '&lt;a&amp;&#039;&gt;') {
    echo "ok\n";
    exit(0);
}
echo "got: {$out}\n";
exit(1);
