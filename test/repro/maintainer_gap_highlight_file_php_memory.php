<?php

$r = highlight_file('php://memory', true);
echo $r === '<code><span style="color: #000000">'."\n".'</span>'."\n".'</code>' ? "ok\n" : "fail\n";
