<?php

echo 'mktime='.(is_int(mktime(null)) ? 'int:yes' : gettype(mktime(null))), "\n";
echo 'gmmktime='.(is_int(gmmktime(null)) ? 'int:yes' : gettype(gmmktime(null))), "\n";
