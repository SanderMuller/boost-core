# Adversarial tarball fixtures

These `.tar.gz` files carry entries that `PharData::addFromString` refuses to
build (`..`, absolute path, symlink), so they are produced once with the system
`tar` command and committed. They exercise `TarballExtractor`'s
validate-before-extract guards (`PATH_TRAVERSAL`, `ABSOLUTE_PATH`, `SYMLINK`).

`.gitattributes` marks `*.tar.gz binary` so the repo's `* text=auto eol=lf`
policy cannot corrupt them on checkout.

## Regenerating

Built on macOS bsdtar 3.5.3; GNU-tar notes inline. Run from this directory:

```bash
export COPYFILE_DISABLE=1   # suppress macOS AppleDouble `._` junk

# symlink.tar.gz — a symlink member (lrwxr-xr-x … evil-link -> /etc/passwd)
work="$(mktemp -d)"; ( cd "$work" && echo real > real.txt && ln -s /etc/passwd evil-link \
  && tar --format=gnutar -czf "$OLDPWD/symlink.tar.gz" real.txt evil-link ); rm -rf "$work"

# absolute.tar.gz — an absolute-path member (leading / kept via -P)
mkdir -p /tmp/boost-fix-abs && echo abs > /tmp/boost-fix-abs/absf.txt
tar --format=gnutar -Pczf absolute.tar.gz /tmp/boost-fix-abs/absf.txt; rm -rf /tmp/boost-fix-abs

# dotdot.tar.gz — a `..` path-traversal member (kept via -P)
work="$(mktemp -d)"; ( cd "$work" && mkdir -p deep/sub && echo dd > deep/target.txt \
  && cd deep/sub && tar --format=gnutar -Pczf "$OLDPWD/dotdot.tar.gz" ../target.txt ); rm -rf "$work"
```

Verify each carries its dangerous entry: `tar -tvzf <fixture>`.

GNU tar (Linux/CI): identical, except a path rewrite uses
`--transform='s#^#../#'` instead of bsdtar's `-s '#^#../#'` — the portable
`cd`+`../` form above needs neither.
