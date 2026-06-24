<?php

echo 'memory=', stream_is_local('php://memory') ? 'true' : 'false', "\n";
echo 'file=', stream_is_local(__FILE__) ? 'true' : 'false', "\n";
