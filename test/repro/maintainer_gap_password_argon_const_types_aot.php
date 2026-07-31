<?php
/**
 * #25818 AOT ConstFetch probe — string algo ids without is_string/password_hash
 * (those paths are separately unreliable/red under AOT on master).
 */
echo PASSWORD_ARGON2I === 'argon2i' ? "argon2i_ok\n" : "argon2i_bad\n";
echo PASSWORD_ARGON2ID === 'argon2id' ? "argon2id_ok\n" : "argon2id_bad\n";
echo PASSWORD_ARGON2I, "\n";
echo PASSWORD_ARGON2ID, "\n";
