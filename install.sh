#!/bin/sh
# Installs Leopard. Run it again to upgrade.
#
#   curl -fsSL https://raw.githubusercontent.com/dinhtungdu/leopard/main/install.sh | sh
#
# It puts one file in ~/.leopard (/var/lib/leopard as root) and a `leopard`
# command in ~/.local/bin (/usr/local/bin as root). Nothing else changes.
# Choose with environment variables:
#   LEOPARD_PHP=8.3|8.4|8.5   the default PHP (default 8.3). Sites can add others later.
#   LEOPARD_VERSION=v0.2.2    a release tag (default: the latest release)
#   LEOPARD_BIN_DIR=DIR       where the leopard command goes
set -eu
repo=https://github.com/dinhtungdu/leopard
die() { echo "leopard install: $*" >&2; exit 1; }

[ "$(uname -s)" = Linux ] || die "Leopard runs on Linux. On macOS or Windows, use a Linux VM, a container, or WSL2."
case $(uname -m) in
  x86_64 | amd64) arch=x86_64 ;;
  aarch64 | arm64) arch=aarch64 ;;
  *) die "There is no Leopard file for $(uname -m). Files exist for x86_64 and aarch64." ;;
esac
php=${LEOPARD_PHP:-8.3}
case $php in 8.3 | 8.4 | 8.5) ;; *) die "LEOPARD_PHP must be 8.3, 8.4, or 8.5." ;; esac
if [ "$(id -u)" = 0 ]; then
  home=${LEOPARD_HOME:-/var/lib/leopard} bin=${LEOPARD_BIN_DIR:-/usr/local/bin}
else
  home=${LEOPARD_HOME:-$HOME/.leopard} bin=${LEOPARD_BIN_DIR:-$HOME/.local/bin}
fi
if [ -n "${LEOPARD_DOWNLOAD_URL:-}" ]; then url=$LEOPARD_DOWNLOAD_URL
elif [ -n "${LEOPARD_VERSION:-}" ]; then url=$repo/releases/download/$LEOPARD_VERSION
else url=$repo/releases/latest/download
fi
file=leopard-php$php-linux-$arch

fetch() {
  if command -v curl >/dev/null; then curl -fsSL -o "$2" "$1"
  elif command -v wget >/dev/null; then wget -qO "$2" "$1"
  else die "Needs curl or wget to download Leopard."
  fi
}

mkdir -p "$home"
tmp=$home/.install.$$
trap 'rm -f "$tmp" "$tmp.sums"' EXIT
echo "Downloading $file..."
fetch "$url/$file" "$tmp" || die "Could not download $url/$file"
fetch "$url/SHA256SUMS" "$tmp.sums" || die "Could not download $url/SHA256SUMS"
want=$(awk -v f="$file" '$2 == f || $2 == "*" f { print $1 }' "$tmp.sums")
got=$({ sha256sum "$tmp" 2>/dev/null || shasum -a 256 "$tmp"; } | cut -d' ' -f1)
[ -n "$want" ] && [ "$want" = "$got" ] || die "The checksum of $file does not match SHA256SUMS. Nothing was installed."
chmod +x "$tmp"

# An upgrade restarts a running Leopard, so it runs the new version.
running=
[ -x "$home/leopard" ] && "$home/leopard" status --json 2>/dev/null | grep -q '"running": true' && running=1
mv "$tmp" "$home/leopard"
echo "Unpacking..."
version=$("$home/leopard" version | sed -n 's/^leopard //p')
[ -n "$running" ] && "$home/leopard" restart >/dev/null && echo "Restarted the running Leopard."

mkdir -p "$bin"
cmd=leopard
if [ -e "$bin/leopard" ] && [ "$(readlink "$bin/leopard")" != "$home/leopard" ]; then
  echo "Left $bin/leopard alone: it is not from this installer." >&2
  cmd=$home/leopard
else
  ln -sfn "$home/leopard" "$bin/leopard"
  case :$PATH: in *":$bin:"*) ;; *) cmd=$bin/leopard ;; esac
fi

cat <<EOF

Leopard${version:+ $version} is installed, with PHP $php.

  $cmd start                  # Apache, PHP, and MariaDB
  $cmd site create mysite     # a WordPress site, ready to log in

For AI agents:  $cmd skill setup
EOF
[ "$cmd" = "$bin/leopard" ] && printf '\nTo type just "leopard", add this to your shell profile:\n  export PATH="%s:$PATH"\n' "$bin"
exit 0
