<?php
try { throw new LogicException("boom");   } catch (LogicException $e) { echo "c1: ", $e->getMessage(), "\n"; }
try { throw new LogicException("second"); } catch (LogicException $e) { echo "c2: ", $e->getMessage(), "\n"; }
