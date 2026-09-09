# CI release test signing key (#36399)

RSA-2048 keypair used only for local/CI smoke of `sign-release-artifacts.sh` /
`verify-release-artifacts.sh` and `pack-phpc-sdk.sh` when
`PHPC_RELEASE_SIGNING_KEY` is unset.

**Not a production release key.** Production releases must set:

```bash
export PHPC_RELEASE_SIGNING_KEY=/path/to/production.pem
export PHPC_RELEASE_SIGNING_PUB=/path/to/production.pub.pem  # optional
```

`script/sign-release-artifacts.sh` prefers this committed key over an ephemeral
keypair so signatures are durable across CI runs (artifact-honesty: the verify
path exercises a stable public key, not a throwaway one).
