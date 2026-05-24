/*
 * Session id buffer for JIT/AOT session_id() (issue #1183).
 *
 * LLVM declares __phpc_session_id_storage as an external global (array type does not
 * emit a definition in standalone AOT objects). This unit provides the storage.
 */

#define PHPC_SESSION_ID_MAX 128

char __phpc_session_id_storage[PHPC_SESSION_ID_MAX + 1];
