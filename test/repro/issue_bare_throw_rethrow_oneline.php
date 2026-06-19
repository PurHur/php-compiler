<?php
try { try { throw new Exception('inner'); } catch (Exception $e) { throw; } } catch (Exception $e) { echo $e->getMessage(), "\n"; }
