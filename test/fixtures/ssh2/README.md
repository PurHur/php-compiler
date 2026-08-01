# ssh2 test fixtures (#26577)

PEM RSA keypairs for `ssh2_auth_pubkey_file` live smoke tests.

- `id_rsa` / `id_rsa.pub` — authorized on the ephemeral test sshd
- `id_rsa_wrong` / `id_rsa_wrong.pub` — negative auth path

These keys are disposable and never used outside CI/local docker repros.
Ubuntu 22.04 sshd may need `PubkeyAcceptedAlgorithms +ssh-rsa` for these RSA keys.
