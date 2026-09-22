#!/bin/sh
# Parental Control plugin installer / updater.
#
# Run once on the firewall as root:
#   fetch -o - https://raw.githubusercontent.com/nycoagung/opnsense-plugin-parentalcontrol/main/install.sh | sh
#
# Files are pulled from the GitHub API, not raw.githubusercontent, and each is
# verified against the git blob SHA the API reports. raw is CDN-cached, lags
# pushes by minutes and is cached per edge, so it can silently serve stale
# content while reporting success.
set -e

GH_OWNER="${GH_OWNER:-nycoagung}"
GH_REPO="${GH_REPO:-opnsense-plugin-parentalcontrol}"
GH_REF="${GH_REF:-main}"

MVC=/usr/local/opnsense/mvc/app
WWW=/usr/local/opnsense/www/js/widgets
SCRIPTS=/usr/local/opnsense/scripts/OPNsense/ParentalControl
ACTIONS=/usr/local/opnsense/service/conf/actions.d

command -v python3 >/dev/null 2>&1 || { echo "python3 is required" >&2; exit 1; }
[ -d "$MVC" ] || { echo "not an OPNsense system: $MVC missing" >&2; exit 1; }

# Every file: <repo path>|<destination>
FILES="
src/opnsense/mvc/app/models/OPNsense/ParentalControl/ParentalControl.xml|$MVC/models/OPNsense/ParentalControl/ParentalControl.xml
src/opnsense/mvc/app/models/OPNsense/ParentalControl/ParentalControl.php|$MVC/models/OPNsense/ParentalControl/ParentalControl.php
src/opnsense/mvc/app/models/OPNsense/ParentalControl/Menu/Menu.xml|$MVC/models/OPNsense/ParentalControl/Menu/Menu.xml
src/opnsense/mvc/app/models/OPNsense/ParentalControl/ACL/ACL.xml|$MVC/models/OPNsense/ParentalControl/ACL/ACL.xml
src/opnsense/mvc/app/controllers/OPNsense/ParentalControl/IndexController.php|$MVC/controllers/OPNsense/ParentalControl/IndexController.php
src/opnsense/mvc/app/controllers/OPNsense/ParentalControl/Api/SettingsController.php|$MVC/controllers/OPNsense/ParentalControl/Api/SettingsController.php
src/opnsense/mvc/app/controllers/OPNsense/ParentalControl/Api/ServiceController.php|$MVC/controllers/OPNsense/ParentalControl/Api/ServiceController.php
src/opnsense/mvc/app/controllers/OPNsense/ParentalControl/forms/device.xml|$MVC/controllers/OPNsense/ParentalControl/forms/device.xml
src/opnsense/mvc/app/controllers/OPNsense/ParentalControl/forms/general.xml|$MVC/controllers/OPNsense/ParentalControl/forms/general.xml
src/opnsense/mvc/app/views/OPNsense/ParentalControl/index.volt|$MVC/views/OPNsense/ParentalControl/index.volt
src/opnsense/scripts/OPNsense/ParentalControl/sync.php|$SCRIPTS/sync.php
src/opnsense/service/conf/actions.d/actions_parentalcontrol.conf|$ACTIONS/actions_parentalcontrol.conf
src/opnsense/www/js/widgets/ParentalControl.js|$WWW/ParentalControl.js
src/opnsense/www/js/widgets/Metadata/ParentalControl.xml|$WWW/Metadata/ParentalControl.xml
install.sh|$SCRIPTS/install.sh
"

fetch_verified() {
    python3 -c '
import base64, hashlib, json, sys, urllib.request
owner, repo, ref, path, dest = sys.argv[1:6]
url = "https://api.github.com/repos/%s/%s/contents/%s?ref=%s" % (owner, repo, path, ref)
req = urllib.request.Request(url, headers={"Accept": "application/vnd.github+json",
                                           "User-Agent": "parentalcontrol-installer"})
with urllib.request.urlopen(req, timeout=60) as r:
    meta = json.load(r)
data = base64.b64decode(meta["content"])
want = meta["sha"]
got = hashlib.sha1(b"blob %d\0" % len(data) + data).hexdigest()
if got != want:
    sys.stderr.write("SHA MISMATCH %s\n  expected %s\n  got      %s\n" % (path, want, got))
    sys.exit(1)
with open(dest, "wb") as f:
    f.write(data)
sys.stderr.write("  %-34s %s  %d bytes\n" % (path.rsplit("/", 1)[-1], want[:12], len(data)))
' "$GH_OWNER" "$GH_REPO" "$GH_REF" "$1" "$2"
}

echo "fetching from github api (${GH_OWNER}/${GH_REPO}@${GH_REF}):"

# Stage every file first; swap them in only once all have verified, so a failed
# or stale download can never leave a half-installed plugin behind.
echo "$FILES" | while IFS='|' read -r src dst; do
    [ -n "$src" ] || continue
    mkdir -p "$(dirname "$dst")"
    fetch_verified "$src" "$dst.pcnew"
done

echo "$FILES" | while IFS='|' read -r src dst; do
    [ -n "$src" ] || continue
    mv "$dst.pcnew" "$dst"
    case "$dst" in *.php|*.py) chmod 0755 "$dst" ;; *) chmod 0644 "$dst" ;; esac
done
chmod 0755 "$SCRIPTS/install.sh"
echo "files installed"

# configd needs restarting for a new action file AND for a new MVC model/menu
service configd restart >/dev/null 2>&1 || true
/usr/local/opnsense/mvc/script/run_migrations.php >/dev/null 2>&1 || true
echo "configd restarted, migrations run"
echo "done - reload the GUI, then see Firewall > Parental Control"
