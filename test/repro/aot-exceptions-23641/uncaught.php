<?php
echo "BEFORE\n";
throw new LogicException("boom from user code");
