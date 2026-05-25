/*
 * Session name buffer for JIT/AOT session_name() (issue #1184).
 *
 * LLVM declares __phpc_session_name_storage as an external global (array type does not
 * emit a definition in standalone AOT objects). This unit provides the storage.
 */

#define PHPC_SESSION_NAME_MAX 128

char __phpc_session_name_storage[PHPC_SESSION_NAME_MAX + 1] = "PHPSESSID";
