<?php

echo 'touch='.(false === touch(null) ? 'false' : gettype(touch(null))), "\n";
echo 'unserialize='.(false === unserialize(null) ? 'false' : gettype(unserialize(null))), "\n";
