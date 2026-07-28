<?php try { throw new RuntimeException("x"); } catch (RuntimeException $e) { echo "caught"; }
