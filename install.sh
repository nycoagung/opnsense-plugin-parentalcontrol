#!/bin/sh
# Parental Control plugin installer / updater.
#
# Run once on the firewall as root:
#   fetch -o - https://raw.githubusercontent.com/nycoagung/opnsense-plugin-parentalcontrol/main/install.sh | sh
#
# Afterwards:  configctl parentalcontrol install
#
# HOW FILES ARE FETCHED AND WHY:
# One call to the GitHub API returns the tree - every path with its git blob SHA.
# The files themselves then come from raw.githubusercontent, which is not rate
# limited, and each one is verified against the SHA from that manifest.
#
# This matters twice over. Fetching per-file from the API costs one rate-limited
# request per file (60/hour unauthenticated), which a handful of installs
# exhausts. And raw is CDN-cached, lags pushes by minutes and is cached per edge,
# so it can serve stale content - which the SHA check now catches instead of
# installing silently. A mismatch is retried, then falls back to the API for
# that one file, and only then gives up.
#
# Set GITHUB_TOKEN to raise the API limit if you ever need to.
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

M="$MVC/models/OPNsense/ParentalControl"
C="$MVC/controllers/OPNsense/ParentalControl"
V="$MVC/views/OPNsense/ParentalControl"
S=src/opnsense

FILES="
$S/mvc/app/models/OPNsense/ParentalControl/ParentalControl.xml|$M/ParentalControl.xml
$S/mvc/app/models/OPNsense/ParentalControl/ParentalControl.php|$M/ParentalControl.php
$S/mvc/app/models/OPNsense/ParentalControl/Menu/Menu.xml|$M/Menu/Menu.xml
$S/mvc/app/models/OPNsense/ParentalControl/ACL/ACL.xml|$M/ACL/ACL.xml
$S/mvc/app/controllers/OPNsense/ParentalControl/IndexController.php|$C/IndexController.php
$S/mvc/app/controllers/OPNsense/ParentalControl/Api/SettingsController.php|$C/Api/SettingsController.php
$S/mvc/app/controllers/OPNsense/ParentalControl/Api/ServiceController.php|$C/Api/ServiceController.php
$S/mvc/app/controllers/OPNsense/ParentalControl/forms/device.xml|$C/forms/device.xml
$S/mvc/app/controllers/OPNsense/ParentalControl/forms/general.xml|$C/forms/general.xml
$S/mvc/app/views/OPNsense/ParentalControl/index.volt|$V/index.volt
$S/scripts/OPNsense/ParentalControl/sync.php|$SCRIPTS/sync.php
$S/service/conf/actions.d/actions_parentalcontrol.conf|$ACTIONS/actions_parentalcontrol.conf
$S/www/js/widgets/ParentalControl.js|$WWW/ParentalControl.js
$S/www/js/widgets/Metadata/ParentalControl.xml|$WWW/Metadata/ParentalControl.xml
install.sh|$SCRIPTS/install.sh
"

hash_of() { [ -f "$1" ] && md5 -q "$1" 2>/dev/null || echo absent; }
ACTIONS_BEFORE=$(hash_of "$ACTIONS/actions_parentalcontrol.conf")
MODEL_BEFORE=$(hash_of "$M/ParentalControl.xml")

ARGS=""
for entry in $FILES; do
    [ -n "$entry" ] || continue
    mkdir -p "$(dirname "${entry#*|}")"
    ARGS="$ARGS $entry"
done

# One process, one manifest request, all files verified before anything is swapped.
python3 -c '
import base64, hashlib, json, os, sys, urllib.request, urllib.error

owner, repo, ref = sys.argv[1:4]
pairs = [a.split("|", 1) for a in sys.argv[4:]]
tok = os.environ.get("GITHUB_TOKEN", "").strip()
hdr = {"Accept": "application/vnd.github+json", "User-Agent": "parentalcontrol-installer"}
if tok:
    hdr["Authorization"] = "Bearer " + tok

def api(url):
    try:
        return json.load(urllib.request.urlopen(urllib.request.Request(url, headers=hdr), timeout=60))
    except urllib.error.HTTPError as e:
        if e.code == 403:
            sys.stderr.write(
                "GitHub API rate limit reached (60/hour per IP unauthenticated).\n"
                "This installer needs exactly ONE API request; wait a few minutes,\n"
                "or set GITHUB_TOKEN to raise the limit.\n")
        raise

tree = api("https://api.github.com/repos/%s/%s/git/trees/%s?recursive=1" % (owner, repo, ref))
shas = {t["path"]: t["sha"] for t in tree.get("tree", []) if t.get("type") == "blob"}

def blob_sha(data):
    return hashlib.sha1(b"blob %d\0" % len(data) + data).hexdigest()

def raw(path):
    url = "https://raw.githubusercontent.com/%s/%s/%s/%s" % (owner, repo, ref, path)
    req = urllib.request.Request(url, headers={"User-Agent": "parentalcontrol-installer",
                                               "Cache-Control": "no-cache"})
    return urllib.request.urlopen(req, timeout=60).read()

fail = False
for src, dst in pairs:
    want = shas.get(src)
    if want is None:
        sys.stderr.write("  %-34s NOT IN REPO\n" % src.rsplit("/", 1)[-1]); fail = True; continue
    data, how = None, ""
    for attempt in (1, 2):
        try:
            d = raw(src)
        except Exception:
            d = None
        if d is not None and blob_sha(d) == want:
            data, how = d, "raw" if attempt == 1 else "raw/retry"
            break
    if data is None:
        # raw is serving stale or unreachable content - fall back to the API for
        # this one file only, so a bad edge costs one request, not fifteen
        meta = api("https://api.github.com/repos/%s/%s/contents/%s?ref=%s" % (owner, repo, src, ref))
        d = base64.b64decode(meta["content"])
        if blob_sha(d) == want:
            data, how = d, "api"
    if data is None:
        sys.stderr.write("  %-34s SHA MISMATCH - refusing\n" % src.rsplit("/", 1)[-1]); fail = True; continue
    with open(dst + ".pcnew", "wb") as f:
        f.write(data)
    sys.stderr.write("  %-34s %s  %6d bytes  via %s\n" % (src.rsplit("/", 1)[-1], want[:12], len(data), how))

sys.exit(1 if fail else 0)
' "$GH_OWNER" "$GH_REPO" "$GH_REF" $ARGS

# Nothing is swapped until every file above verified.
for entry in $FILES; do
    [ -n "$entry" ] || continue
    dst=${entry#*|}
    mv "$dst.pcnew" "$dst"
    case "$dst" in *.php) chmod 0755 "$dst" ;; *) chmod 0644 "$dst" ;; esac
done
chmod 0755 "$SCRIPTS/install.sh"
echo "files installed"

# This script is reachable as 'configctl parentalcontrol install', and restarting
# configd from a script configd launched would kill it mid-run - so only restart
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
