#!/bin/sh
# Parental Control plugin installer / updater.
#
# Bootstrap (one command, no GitHub API involved):
#   fetch -qo /tmp/pc.tgz https://codeload.github.com/nycoagung/opnsense-plugin-parentalcontrol/tar.gz/refs/heads/main && \
#     rm -rf /tmp/pcx && mkdir -p /tmp/pcx && tar -xzf /tmp/pc.tgz -C /tmp/pcx && \
#     sh /tmp/pcx/opnsense-plugin-parentalcontrol-main/install.sh
#
# Afterwards:  configctl parentalcontrol install
#
# WHY codeload AND NOT THE API OR raw:
#   - the API costs one rate-limited request per file (60/hour per IP, and the
#     firewall's own public IP is what counts). Fifteen files exhausted it.
#   - raw.githubusercontent is CDN-cached, lags pushes by minutes and is cached
#     per edge, so it silently serves stale content.
#   - codeload serves the git ref directly: one request for the whole tree, no
#     rate limit, and current. Measured: raw was serving a five-commit-old
#     installer at the same moment codeload matched the repo byte for byte.
#
# If this script is run from an already-extracted archive it installs from there
# rather than downloading again, so the bootstrap above costs exactly one fetch.
set -e

GH_OWNER="${GH_OWNER:-nycoagung}"
GH_REPO="${GH_REPO:-opnsense-plugin-parentalcontrol}"
GH_REF="${GH_REF:-main}"

MVC=/usr/local/opnsense/mvc/app
WWW=/usr/local/opnsense/www/js/widgets
SCRIPTS=/usr/local/opnsense/scripts/OPNsense/ParentalControl
ACTIONS=/usr/local/opnsense/service/conf/actions.d
M="$MVC/models/OPNsense/ParentalControl"
C="$MVC/controllers/OPNsense/ParentalControl"
V="$MVC/views/OPNsense/ParentalControl"
P=src/opnsense

[ -d "$MVC" ] || { echo "not an OPNsense system: $MVC missing" >&2; exit 1; }

FILES="
$P/mvc/app/models/OPNsense/ParentalControl/ParentalControl.xml|$M/ParentalControl.xml
$P/mvc/app/models/OPNsense/ParentalControl/ParentalControl.php|$M/ParentalControl.php
$P/mvc/app/models/OPNsense/ParentalControl/Menu/Menu.xml|$M/Menu/Menu.xml
$P/mvc/app/models/OPNsense/ParentalControl/ACL/ACL.xml|$M/ACL/ACL.xml
$P/mvc/app/controllers/OPNsense/ParentalControl/IndexController.php|$C/IndexController.php
$P/mvc/app/controllers/OPNsense/ParentalControl/Api/SettingsController.php|$C/Api/SettingsController.php
$P/mvc/app/controllers/OPNsense/ParentalControl/Api/ServiceController.php|$C/Api/ServiceController.php
$P/mvc/app/controllers/OPNsense/ParentalControl/forms/device.xml|$C/forms/device.xml
$P/mvc/app/controllers/OPNsense/ParentalControl/forms/general.xml|$C/forms/general.xml
$P/mvc/app/views/OPNsense/ParentalControl/index.volt|$V/index.volt
$P/scripts/OPNsense/ParentalControl/sync.php|$SCRIPTS/sync.php
$P/service/conf/actions.d/actions_parentalcontrol.conf|$ACTIONS/actions_parentalcontrol.conf
$P/www/js/widgets/ParentalControl.js|$WWW/ParentalControl.js
$P/www/js/widgets/Metadata/ParentalControl.xml|$WWW/Metadata/ParentalControl.xml
install.sh|$SCRIPTS/install.sh
"

hash_of() { [ -f "$1" ] && md5 -q "$1" 2>/dev/null || echo absent; }
ACTIONS_BEFORE=$(hash_of "$ACTIONS/actions_parentalcontrol.conf")
MODEL_BEFORE=$(hash_of "$M/ParentalControl.xml")

HERE=$(dirname "$0")
CLEAN=""
if [ -d "$HERE/$P" ]; then
    SRC="$HERE"
    echo "installing from $SRC"
else
    TMP=$(mktemp -d /tmp/pcinst.XXXXXX)
    CLEAN="$TMP"
    echo "fetching ${GH_OWNER}/${GH_REPO}@${GH_REF} from codeload"
    fetch -qT 30 -o "$TMP/src.tgz" \
        "https://codeload.github.com/${GH_OWNER}/${GH_REPO}/tar.gz/refs/heads/${GH_REF}"
    tar -xzf "$TMP/src.tgz" -C "$TMP"
    SRC=$(find "$TMP" -mindepth 1 -maxdepth 1 -type d | head -1)
    [ -n "$SRC" ] && [ -d "$SRC/$P" ] || { echo "archive did not contain $P" >&2; exit 1; }
fi

# Check the whole set is present before touching anything on disk, so a
# truncated archive cannot leave a half-installed plugin behind.
for entry in $FILES; do
    [ -n "$entry" ] || continue
    s=${entry%%|*}
    [ -f "$SRC/$s" ] || { echo "missing from source: $s" >&2; exit 1; }
done

# Stage beside the destination, then rename into place.
#
# This is not just about atomicity. This script installs ITSELF, and sh reads a
# script incrementally: cp rewrites the same inode, so the running shell's read
# offset lands in shifted bytes and it dies mid-file. Reproduced exactly - a
# self-cp of a different-length script exits 2, which is what
# 'configctl parentalcontrol install' reported. mv creates a new inode and
# leaves the running script's open file untouched.
for entry in $FILES; do
    [ -n "$entry" ] || continue
    s=${entry%%|*}; d=${entry#*|}
    mkdir -p "$(dirname "$d")"
    cp "$SRC/$s" "$d.pcnew"
done
for entry in $FILES; do
    [ -n "$entry" ] || continue
    s=${entry%%|*}; d=${entry#*|}
    mv "$d.pcnew" "$d"
    case "$d" in *.php|*.sh) chmod 0755 "$d" ;; *) chmod 0644 "$d" ;; esac
    printf '  %-34s %6d bytes\n' "$(basename "$s")" "$(wc -c < "$d" | tr -d ' ')"
done
[ -n "$CLEAN" ] && rm -rf "$CLEAN"
echo "files installed"

# This script is reachable as 'configctl parentalcontrol install'; restarting
# configd from a script configd launched would kill it mid-run, so only restart
# when the action file actually changed.
if [ "$ACTIONS_BEFORE" != "$(hash_of "$ACTIONS/actions_parentalcontrol.conf")" ]; then
    service configd restart >/dev/null 2>&1 || true
    echo "configd action file changed - configd restarted"
else
    echo "configd action file unchanged - not restarting"
fi
if [ "$MODEL_BEFORE" != "$(hash_of "$M/ParentalControl.xml")" ]; then
    /usr/local/opnsense/mvc/script/run_migrations.php >/dev/null 2>&1 || true
    echo "model changed - migrations run"
fi
echo "done - reload the GUI, then see Firewall > Parental Control"
