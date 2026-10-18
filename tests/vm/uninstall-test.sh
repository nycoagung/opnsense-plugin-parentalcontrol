#!/bin/sh
#
# Parental Control on a test VM: install, the messaging block, and uninstall.
#
# Run it as root on a DISPOSABLE OPNsense 26.7 virtual machine that has internet
# access, never on a firewall in use. It installs and removes the plugin several
# times, points /etc/resolv.conf at nothing for half a minute and drops
# connections.
#
#   sh uninstall-test.sh /path/to/extracted/repo [/path/to/extracted/0.0.2]
#
# Getting the tree onto the VM, either way:
#   - Bootstrap from codeload with GH_REF=messaging-block, once the maintainer has
#     approved pushing the branch. This line also works from csh:
#       fetch -qo /tmp/pc.tgz https://codeload.github.com/nycoagung/opnsense-plugin-parentalcontrol/tar.gz/refs/heads/messaging-block && mkdir -p /tmp/pcx && tar -xzf /tmp/pc.tgz -C /tmp/pcx
#     The tree is then /tmp/pcx/opnsense-plugin-parentalcontrol-messaging-block.
#   - Or, on the Mac:
#       git archive --format=tar.gz --prefix=pc/ messaging-block > work/pc.tgz
#     Copy work/pc.tgz to the VM and run tar -xzf pc.tgz -C /tmp. The tree is /tmp/pc.
#
# The second argument is optional: a 0.0.2 tree for the upgrade check, made the
# same way from git archive --format=tar.gz --prefix=pc-0.0.2/ 0.0.2. Without it,
# that check prints SKIP. For example:
#   sh /tmp/pc/tests/vm/uninstall-test.sh /tmp/pc /tmp/pc-0.0.2 2>&1 | tee /root/uninstall-test.log
#
# Every check prints one line:
#   PASS <name>
#   FAIL <name>: <detail>
#   REVIEW <name>: <diff>  config.xml differs from the copy saved at the start
#                          only by empty elements that core's model serialisation
#                          leaves, such as an empty <rules/>. The maintainer
#                          decides whether those count.
#   SKIP <name>: <reason>  only where a precondition is absent
# Comparing config.xml with the saved copy leaves out revision metadata: the
# top-level <revision>, and the persisted_at stamp core puts on a model it saves.
# A summary follows, and the exit status is 1 if anything failed. The run takes
# about a quarter of an hour, partly because it waits for moments when cron's
# per-minute sync is not running.
#
# Beyond the plugin's own files, settings and objects it changes:
#   - /etc/resolv.conf, for one check;
#   - one firewall rule of its own, added and deleted again (the refusal check);
#   - background helpers: lock holders, idle connections and uninstall runs.
# The VM's own address is a test device for one check, and the installer and
# uninstaller restart configd. At the end config.xml is compared with the copy
# saved at the start. Work files, including that copy, stay in /tmp/pctest.
#
# The EXIT trap, which also runs on Ctrl-C, puts resolv.conf back, deletes the
# test's own firewall rule if it is still there, and stops the helpers. An
# uninstall.sh running in the background is not killed: the trap stops the
# uninstall.php it started, and uninstall.sh then ends by itself. An interrupted
# run otherwise leaves the plugin as the current check had it; preflight wants a
# clean VM, so start again from one.
#
# configd hides a script's stderr and exit status: it answers "Error (1)" or
# "Execute error". So wherever a check needs a message or an exit code, it runs
# the plugin's script directly. configctl is used where its "OK" is the point.

SCRIPTS=/usr/local/opnsense/scripts/OPNsense/ParentalControl
VIEW=/usr/local/opnsense/mvc/app/views/OPNsense/ParentalControl/index.volt
DATA=/var/db/parentalcontrol            # DATA_DIR in lib/markers.php
MARKER=$DATA/uninstalling               # UNINSTALL_MARKER in lib/markers.php
CONF=/conf/config.xml
W=/tmp/pctest
PC="php $W/pc.php"
DEV=192.0.2.10                          # the test device; documentation addresses only
# the description markers of the plugin's objects (lib/markers.php), then its cron job's
MARKERS='Parental Control: devices currently denied internet
Parental Control: local networks
Parental Control: block internet
Parental Control: devices currently denied messaging
Parental Control: messaging servers
Parental Control: block messaging
Parental Control: block notification ports
Parental Control: evaluate device schedules'
WAITING='waiting for a running sync to finish'      # uninstall.php while the lock is held
PAUSED='uninstall in progress - nothing changed'    # sync.php once uninstall has begun

TREE=
OLD=
[ -z "${1:-}" ] || TREE=$(cd "$1" 2>/dev/null && pwd)
[ -z "${2:-}" ] || OLD=$(cd "$2" 2>/dev/null && pwd || echo "$2")

# ---- results ----------------------------------------------------------------

NPASS=0
NFAIL=0
NREVIEW=0
NSKIP=0
P=                                      # the current check's problems
pass()    { echo "PASS $1"; NPASS=$((NPASS + 1)); }
fail()    { echo "FAIL $1: $2"; NFAIL=$((NFAIL + 1)); }
review()  { echo "REVIEW $1: $2"; NREVIEW=$((NREVIEW + 1)); }
skip()    { echo "SKIP $1: $2"; NSKIP=$((NSKIP + 1)); }
problem() { P="$P${P:+; }$1"; }
verdict() { if [ -z "$P" ]; then pass "$1"; else fail "$1" "$P"; fi; P=; }
oneline() { printf '%s' "$1" | tr '\n' '|' | cut -c1-500; }
section() { echo; echo "== $1"; }
finish() {
    echo
    echo "summary: $NPASS passed, $NFAIL failed, $NREVIEW to review, $NSKIP skipped"
    [ "$NFAIL" -eq 0 ] || exit 1
    exit 0
}

# ---- the firewall -----------------------------------------------------------

tbl()       { pfctl -t "$1" -T show 2>/dev/null | awk '{print $1}'; }
listed()    { tbl "$1" | grep -qxF -- "$2"; }
count()     { tbl "$1" | grep -c .; }
has_state() { pfctl -s state 2>/dev/null | grep -qF -- " $1 "; }
csync()     { configctl parentalcontrol sync 2>&1; }
plugin_lines() { grep -c 'Parental Control:' "$CONF"; }
missing_markers() {
    printf '%s\n' "$MARKERS" | while IFS= read -r _mk; do
        grep -qF -- "$_mk" "$CONF" || printf '%s; ' "$_mk"
    done
}
# problems unless every plugin object, the device's entry and the servers are there
in_place() {
    _m=$(missing_markers)
    [ -z "$_m" ] || problem "missing from config.xml: $_m"
    listed NoMessaging "$DEV" || problem "$DEV not in NoMessaging"
    [ "$(count Messaging_Servers)" -gt 0 ] || problem "Messaging_Servers is empty"
}
# every destination in an install.sh's FILES list, expanded with its own variables
dests() {
    # shellcheck disable=SC2154 # FILES comes from the eval
    (eval "$(sed -n -E -e '/^(MVC|WWW|SCRIPTS|ACTIONS|M|C|V|P)=/p' -e '/^FILES="$/,/^"$/p' "$1")"
     for _e in $FILES; do echo "${_e#*|}"; done)
}
missing_files() { for _f in $DESTS; do [ -e "$_f" ] || printf '%s ' "$_f"; done; }
present_files() { for _f in $DESTS; do [ ! -e "$_f" ] || printf '%s ' "$_f"; done; }
md5s() { for _f in $DESTS; do printf '%s %s\n' "$(md5 -q "$_f" 2>/dev/null || echo absent)" "$_f"; done; }
pcinst() { for _d in /tmp/pcinst.*; do [ ! -e "$_d" ] || echo "$_d"; done; }

# wait_file FILE SECONDS [TEXT]: until FILE exists, and holds TEXT if given
wait_file() {
    _n=0
    while [ "$_n" -le "$2" ]; do
        if [ -e "$1" ] && { [ -z "${3:-}" ] || grep -qF -- "$3" "$1"; }; then return 0; fi
        sleep 1
        _n=$((_n + 1))
    done
    return 1
}
# A quiet moment: seconds 05 to 40 of a minute, while no sync holds the lock.
# Cron starts a sync every minute; one that waited for the lock could otherwise
# undo a config-only uninstall, or make a timed run wait.
calm() {
    _n=0
    while [ "$_n" -lt 120 ]; do
        _s=$(date +%S)
        _s=${_s#0}
        if [ "$_s" -ge 5 ] && [ "$_s" -le 40 ] && $PC lock-free; then return 0; fi
        sleep 1
        _n=$((_n + 1))
    done
    return 1
}
# the installed installer, with a fake codeload that serves TGZ (or fails)
installed_install() {
    : > "$W/fetch.log"
    PATH="$W/bin:$PATH" FAKE_TGZ="$1" FAKE_FETCH_FAIL="${2:-}" sh "$SCRIPTS/install.sh" 2>&1
}
# Install the tree again, once, if a check left the files broken or the marker
# behind, so later sections start from a working install. Called after the
# checks, which have recorded the failure.
repair() {
    if [ -n "$(missing_files)" ] || [ -e "$MARKER" ] || ! cmp -s "$SCRIPTS/install.sh" "$TREE/install.sh"; then
        sh "$TREE/install.sh" > /dev/null 2>&1
    fi
}

# ---- background helpers and the trap ----------------------------------------

# The helpers that may still be running, as " pid pid ". A PID leaves the list
# once its process has been waited for: after that, the PID can be reused.
BG=' '
HOLDER=                     # the current lock holder
USH=                        # an uninstall.sh running in the background (8b)
RULE=                       # the uuid of the test's own firewall rule while it exists
track()  { BG="$BG$1 "; }
forget() { case $BG in *" $1 "*) BG="${BG%% "$1" *} ${BG#* "$1" }" ;; esac; }
reap()   { wait "$1"; _rc=$?; forget "$1"; return "$_rc"; }
# one of this script's helpers, by its command line, so that a PID that now
# belongs to some other process is never signalled
ours()   { ps -p "$1" -o args= 2>/dev/null | grep -q -e 'pc\.php' -e 'ParentalControl'; }
stop()   { ! ours "$1" || kill "$1" 2>/dev/null; reap "$1" 2>/dev/null; }

hold_lock() {               # hold_lock SECONDS: stand in for a running sync
    rm -f "$W/held"
    $PC hold-lock "$1" "$W/held" &
    HOLDER=$!
    track "$HOLDER"
    wait_file "$W/held" 60
}
release() { stop "$HOLDER"; }   # now, or after the holder has let go by itself

# shellcheck disable=SC2329 # run by the EXIT trap
cleanup() {
    # an uninstall.sh in the background: stop the uninstall.php it runs, whose own
    # handler removes the marker. uninstall.sh then stops by itself or, if
    # uninstall.php had already finished, completes the removal.
    if [ -n "$USH" ]; then
        for _c in $(pgrep -P "$USH" 2>/dev/null); do
            ! ps -p "$_c" -o args= 2>/dev/null | grep -q 'uninstall\.php' || kill "$_c" 2>/dev/null
        done
        wait "$USH" 2>/dev/null
    fi
    for _p in $BG; do stop "$_p"; done
    [ -z "$RULE" ] || $PC rule-del "$RULE" > /dev/null 2>&1
    if [ -f "$W/resolv.conf.orig" ]; then
        cp -p "$W/resolv.conf.orig" /etc/resolv.conf && rm -f "$W/resolv.conf.orig"
    fi
    [ ! -f "$W/messaging.php.aside" ] || mv "$W/messaging.php.aside" "$SCRIPTS/lib/messaging.php"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

# ---- canonical config.xml ---------------------------------------------------

# compare_config NAME [plugin]: config.xml against the copy saved by preflight,
# ignoring the top-level revision and every persisted_at stamp, and the plugin's
# subtree when "plugin" is given
compare_config() {
    if ! _h1=$($PC canon "$W/baseline.xml" "$W/base.xml" ${2:+"$2"}) ||
        ! _h2=$($PC canon "$CONF" "$W/now.xml" ${2:+"$2"}); then
        fail "$1" "config.xml could not be canonicalised"
        return
    fi
    if [ "$_h1" = "$_h2" ]; then
        pass "$1"
        return
    fi
    _d=$(diff -u "$W/base.xml" "$W/now.xml")
    if [ "$($PC canon "$W/baseline.xml" /dev/null ${2:+"$2"} strip)" = \
         "$($PC canon "$CONF" /dev/null ${2:+"$2"} strip)" ]; then
        review "$1" "only empty elements differ
$_d"
    else
        fail "$1" "differs from the saved copy
$_d"
    fi
}

# after a full uninstall: files, data, objects and cron job gone, and config.xml
# as saved at the start
removed_checks() {          # removed_checks PREFIX [plugin]
    _f=$(present_files)
    [ -z "$_f" ] || problem "files left: $_f"
    for _d in "$SCRIPTS" "${VIEW%/*}" /usr/local/opnsense/mvc/app/models/OPNsense/ParentalControl \
              /usr/local/opnsense/mvc/app/controllers/OPNsense/ParentalControl "$DATA"; do
        [ ! -e "$_d" ] || problem "$_d left"
    done
    verdict "$1: files and data directory gone"
    [ "$(plugin_lines)" = 0 ] || problem "$(plugin_lines) 'Parental Control:' lines in config.xml"
    ! grep -q '<origin>parentalcontrol</origin>' "$CONF" || problem "the cron job is still in config.xml"
    verdict "$1: no plugin object or cron job left"
    compare_config "$1: config.xml equals the saved copy" "${2:-}"
}
tables_gone() {             # tables_gone PREFIX ALIAS: ALIAS is the internet alias's name
    for _t in "$2" NoMessaging Messaging_Servers; do
        ! pfctl -s Tables 2>/dev/null | grep -qxF "$_t" ||
            problem "still listed: $(pfctl -vs Tables 2>/dev/null | grep -E "[[:space:]]$_t\$" | tr -s '\t ' ' ') ($(count "$_t") entries)"
    done
    verdict "$1: none of the three tables in pfctl -s Tables"
}

# ---- 1. preflight -----------------------------------------------------------

section "1 preflight"
[ "$(id -u)" = 0 ] || problem "not running as root"
[ -d /usr/local/opnsense ] || problem "/usr/local/opnsense missing: not OPNsense"
if [ -z "$TREE" ] || [ ! -f "$TREE/install.sh" ] || [ ! -d "$TREE/src/opnsense" ]; then
    problem "usage: sh uninstall-test.sh /path/to/extracted/repo [/path/to/extracted/0.0.2]"
fi
case "$TREE/" in "$W"/*) problem "the tree must not be under $W" ;; esac
for _p in "$SCRIPTS" /usr/local/opnsense/mvc/app/models/OPNsense/ParentalControl \
          /usr/local/opnsense/service/conf/actions.d/actions_parentalcontrol.conf "$DATA"; do
    [ ! -e "$_p" ] || problem "$_p exists: the plugin is installed"
done
! grep -qE '<ParentalControl|Parental Control:' "$CONF" ||
    problem "config.xml holds Parental Control settings or objects: start from a clean VM"
if [ -z "$P" ]; then
    rm -rf "$W"
    { mkdir -p "$W/bin" && cp -p "$CONF" "$W/baseline.xml"; } || problem "cannot write $W"
fi
verdict "preflight"
[ "$NFAIL" -eq 0 ] || finish
DESTS=$(dests "$TREE/install.sh")

# The helper: model edits as uninstall.sh --purge makes them, JSON and XML
# reading, and the background holders.
cat > "$W/pc.php" <<'PHP'
<?php

/* Helper for tests/vm/uninstall-test.sh, one command per call. */

const CONFIG_XML = '/conf/config.xml';
const LOCK_FILE = '/var/db/parentalcontrol/sync.lock';      /* DATA_DIR/sync.lock */
const DATA_FILE = '/usr/local/opnsense/scripts/OPNsense/ParentalControl/messaging.json';

$cmd = $argv[1] ?? '';
$a = array_slice($argv, 2);

/* the firewall's classes, loaded the way sync.php loads them; config.inc also
   sets the firewall's timezone, which sync.php's schedule uses too */
if (in_array($cmd, ['general', 'add', 'set', 'del', 'window', 'alias-ok', 'rule-add', 'rule-del'], true)) {
    require_once('config.inc');
}

/* validate, then save; every message goes to stderr, as the plugin's saveModel() does */
function save(\OPNsense\Base\BaseModel $mdl): void
{
    $val = $mdl->performValidation();
    foreach ($val as $msg) {
        fwrite(STDERR, $msg->getField() . ': ' . $msg->getMessage() . "\n");
    }
    if ($val->count() > 0) {
        exit(1);
    }
    $mdl->serializeToConfig();
    \OPNsense\Core\Config::getInstance()->save();
}

/* [uuid, device] for the device named $name */
function device(\OPNsense\ParentalControl\ParentalControl $mdl, string $name): array
{
    foreach ($mdl->devices->iterateItems() as $uuid => $dev) {
        if ($dev->name->getValue() === $name) {
            return [$uuid, $dev];
        }
    }
    fwrite(STDERR, "no device named $name\n");
    exit(1);
}

switch ($cmd) {
    case 'general':             /* general FIELD VALUE */
        $mdl = new \OPNsense\ParentalControl\ParentalControl();
        $mdl->general->{$a[0]} = $a[1];
        save($mdl);
        break;
    case 'add':                 /* add NAME ADDRESS MESSAGING: a device whose internet is always allowed */
        $mdl = new \OPNsense\ParentalControl\ParentalControl();
        $dev = $mdl->devices->Add();
        $dev->name = $a[0];
        $dev->address = $a[1];
        $dev->mode = 'allow';
        $dev->messaging = $a[2];
        save($mdl);
        break;
    case 'set':                 /* set NAME FIELD VALUE */
        $mdl = new \OPNsense\ParentalControl\ParentalControl();
        [, $dev] = device($mdl, $a[0]);
        $dev->{$a[1]} = $a[2];
        save($mdl);
        break;
    case 'del':                 /* del NAME */
        $mdl = new \OPNsense\ParentalControl\ParentalControl();
        [$uuid] = device($mdl, $a[0]);
        $mdl->devices->del($uuid);
        save($mdl);
        break;
    case 'window':              /* window NAME FROM TO: messaging follows now+FROM .. now+TO minutes */
        /* HH:MM modulo a day: a window may cross midnight, which the schedule handles */
        $now = (int)date('H') * 60 + (int)date('i');
        $at = fn(int $offset): string => sprintf(
            '%02d:%02d',
            intdiv(($now + $offset + 2880) % 1440, 60),
            ($now + $offset + 2880) % 60
        );
        $mdl = new \OPNsense\ParentalControl\ParentalControl();
        [, $dev] = device($mdl, $a[0]);
        $dev->messaging = 'schedule';
        $dev->allow_from = $at((int)$a[1]);
        $dev->allow_to = $at((int)$a[2]);
        save($mdl);
        echo $at((int)$a[1]), '-', $at((int)$a[2]), "\n";
        break;
    case 'alias-ok':            /* alias-ok NAME: exit 1, saying why, when the model rejects it; saves nothing */
        $mdl = new \OPNsense\ParentalControl\ParentalControl();
        $mdl->general->alias_name = $a[0];
        $why = [];
        foreach ($mdl->performValidation() as $msg) {
            if (str_ends_with((string)$msg->getField(), 'alias_name')) {
                $why[] = (string)$msg->getMessage();
            }
        }
        echo implode("\n", $why), "\n";
        exit($why === [] ? 0 : 1);
    case 'rule-add':            /* rule-add DESCRIPTION SOURCE: a user's floating rule; prints its uuid */
        $flt = new \OPNsense\Firewall\Filter();
        $rule = $flt->rules->rule->Add();
        $rule->enabled = '1';
        $rule->action = 'block';
        $rule->quick = '1';
        $rule->direction = 'in';
        $rule->ipprotocol = 'inet';
        $rule->source_net = $a[1];
        $rule->description = $a[0];
        save($flt);
        foreach ($flt->rules->rule->iterateItems() as $uuid => $item) {
            if ($item->description->getValue() === $a[0]) {
                echo $uuid, "\n";
            }
        }
        break;
    case 'rule-del':            /* rule-del UUID */
        $flt = new \OPNsense\Firewall\Filter();
        if (!$flt->rules->rule->del($a[0])) {
            fwrite(STDERR, "no rule {$a[0]}\n");
            exit(1);
        }
        save($flt);
        break;
    case 'jget':                /* jget PATH: one value of the JSON on stdin, as JSON; PATH as a.0.b */
        $value = json_decode((string)stream_get_contents(STDIN), true);
        if (!is_array($value)) {
            echo "unparseable\n";
            exit(1);
        }
        foreach (explode('.', $a[0]) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                echo "missing\n";
                exit(1);
            }
            $value = $value[$key];
        }
        echo json_encode($value, JSON_UNESCAPED_SLASHES), "\n";
        break;
    case 'canon':               /* canon FILE OUT [plugin] [strip]: prints the md5 of the C14N form, OUT gets it formatted */
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        if (!@$doc->load($a[0], LIBXML_NONET)) {
            fwrite(STDERR, "cannot parse {$a[0]}\n");
            exit(1);
        }
        $xp = new DOMXPath($doc);
        $drop = in_array('plugin', $a, true) ? '/*/revision | /*/OPNsense/ParentalControl' : '/*/revision';
        foreach (iterator_to_array($xp->query($drop)) as $node) {
            $node->parentNode->removeChild($node);
        }
        /* a model's save stamps it, whatever changed, so like the revision it is metadata */
        foreach (iterator_to_array($xp->query('//@persisted_at')) as $attr) {
            $attr->ownerElement->removeAttributeNode($attr);
        }
        /* "strip": drop every element with neither a child element nor text, until none is left */
        while (in_array('strip', $a, true)
            && ($empty = iterator_to_array($xp->query('/*//*[not(*)][not(normalize-space())]'))) !== []) {
            foreach ($empty as $node) {
                $node->parentNode->removeChild($node);
            }
        }
        $c14n = $doc->C14N();
        $pretty = new DOMDocument();
        $pretty->preserveWhiteSpace = false;
        $pretty->formatOutput = true;
        $pretty->loadXML($c14n);
        file_put_contents($a[1], $pretty->saveXML($pretty->documentElement) . "\n");
        echo md5($c14n), "\n";
        break;
    case 'hold-lock':           /* hold-lock SECONDS READY: take the sync lock, touch READY, hold it */
        @mkdir(dirname(LOCK_FILE), 0700);
        $lock = fopen(LOCK_FILE, 'c');
        flock($lock, LOCK_EX);
        touch($a[1]);
        sleep((int)$a[0]);
        break;
    case 'lock-free':           /* lock-free: exit 0 when no sync holds the lock; creates nothing */
        $lock = is_file(LOCK_FILE) ? fopen(LOCK_FILE, 'r') : false;
        exit($lock === false || flock($lock, LOCK_EX | LOCK_NB) ? 0 : 1);
    case 'hold-conn':           /* hold-conn HOST PORT SECONDS OUT: an idle IPv4 connection; OUT gets "local remote" */
        $ip = gethostbyname($a[0]);
        $sock = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            ? @stream_socket_client("tcp://$ip:{$a[1]}", $errno, $error, 10) : false;
        file_put_contents($a[3] . '.tmp', $sock
            ? stream_socket_get_name($sock, false) . ' ' . stream_socket_get_name($sock, true) . "\n"
            : "fail $ip:{$a[1]} " . ($error ?? 'not resolved') . "\n");
        rename($a[3] . '.tmp', $a[3]);
        if ($sock) {
            sleep((int)$a[2]);
        }
        break;
    case 'volt':                /* volt FILE: compile a view the way core's ControllerBase sets up Volt */
        $class = 'Phalcon\Mvc\View\Engine\Volt\Compiler';
        if (!class_exists($class)) {
            echo "this PHP has no Phalcon Volt compiler\n";
            exit(2);
        }
        try {
            $volt = new $class();
            foreach (['theme_file_or_default' => 'view_fetch_themed_filename',
                      'file_exists' => 'view_file_exists', 'cache_safe' => 'view_cache_safe'] as $name => $function) {
                $volt->addFunction($name, $function);
            }
            $volt->addFilter('safe', 'view_html_safe');
            $volt->compileString((string)file_get_contents($a[0]));
        } catch (\Throwable $e) {
            echo $e->getMessage(), "\n";
            exit(1);
        }
        break;
    case 'first':               /* first KEY: the first entry of messaging.json's notifications.KEY */
        $data = json_decode((string)@file_get_contents(DATA_FILE), true);
        $list = (array)($data['notifications'][$a[0]] ?? []);
        echo is_string($list[0] ?? null) ? $list[0] : '', "\n";
        break;
    case 'wns':                 /* wns N: the first N WNS /32 entries of messaging.json, bare */
        $data = json_decode((string)@file_get_contents(DATA_FILE), true);
        $ranges = (array)($data['notifications']['microsoft_ranges'] ?? []);
        $hosts = array_values(array_filter($ranges, fn($r): bool => is_string($r) && str_ends_with($r, '/32')));
        foreach (array_slice($hosts, 0, (int)$a[0]) as $range) {
            echo substr($range, 0, -3), "\n";
        }
        break;
    case 'poll-signal':         /* poll-signal PID: TERM PID once config.xml changes; prints sent, gone or timeout */
        clearstatcache();
        $before = @stat(CONFIG_XML);
        for ($i = 0; $i < 6000; $i++) {
            usleep(10000);
            clearstatcache();
            $st = @stat(CONFIG_XML);
            if ($st === false || [$st['size'], $st['mtime'], $st['ctime']] !== [$before['size'], $before['mtime'], $before['ctime']]) {
                exec('kill -TERM ' . (int)$a[0] . ' 2>/dev/null', $out, $rc);
                echo $rc === 0 ? "sent\n" : "gone\n";
                exit(0);
            }
        }
        echo "timeout\n";
        break;
    default:
        fwrite(STDERR, "unknown command: $cmd\n");
        exit(2);
}
PHP

# ---- 2. install ---------------------------------------------------------------

section "2 install"
out=$(sh "$TREE/install.sh" 2>&1); rc=$?
[ "$rc" = 0 ] || problem "exit $rc: $(oneline "$out")"
_m=$(missing_files)
[ -z "$_m" ] || problem "missing: $_m"
verdict "install: from the tree, every file in place"
[ "$NFAIL" -eq 0 ] || finish           # nothing else can run without the files
printf '%s\n' "$out" | grep -qxF "installing from $TREE" || problem "no line 'installing from $TREE'"
! printf '%s\n' "$out" | grep -q '^fetching' || problem "it downloaded: $(oneline "$out")"
verdict "install: a tree installs itself, with no download"

out=$($PC volt "$VIEW" 2>&1); rc=$?
case $rc in
    0) pass "install: the settings page's view compiles" ;;
    2) skip "install: the settings page's view compiles" "$out" ;;
    *) fail "install: the settings page's view compiles" "$(oneline "$out")" ;;
esac

# The installer hand-over, through a fake codeload: fetch is shimmed on PATH and
# copies a tarball of the tree, laid out as codeload's is (one top-level directory).
cat > "$W/bin/fetch" <<'EOF'
#!/bin/sh
# stand-in for fetch(1): log the call, then copy $FAKE_TGZ to the -o path, or fail
echo "fetch $*" >> /tmp/pctest/fetch.log
[ -z "$FAKE_FETCH_FAIL" ] || exit 1
while [ $# -gt 0 ]; do
    case $1 in -o) out=$2; shift ;; esac
    shift
done
cp "$FAKE_TGZ" "$out"
EOF
chmod 0755 "$W/bin/fetch"
base=$(basename "$TREE")
tar -czf "$W/good.tgz" -C "$(dirname "$TREE")" "$base"
mkdir -p "$W/bad" && cp -R "$TREE" "$W/bad/" &&
    rm "$W/bad/$base/src/opnsense/scripts/OPNsense/ParentalControl/lib/firewall.php" &&
    tar -czf "$W/bad.tgz" -C "$W/bad" "$base"

# The installed copy's own list loses a file; the archive's list must win. A trap
# that fired in one of the installer's subshells would delete the download first.
sed -i '' '\#lib/serverlist.php|#d' "$SCRIPTS/install.sh"
rm -f "$SCRIPTS/lib/serverlist.php"
before=$(pcinst)
out=$(installed_install "$W/good.tgz"); rc=$?
[ "$rc" = 0 ] || problem "exit $rc: $(oneline "$out")"
printf '%s\n' "$out" | grep -q '^fetching' || problem "no 'fetching' line"
printf '%s\n' "$out" | grep -q '^installing from /tmp/pcinst\.' || problem "the downloaded installer did not run"
[ -f "$SCRIPTS/lib/serverlist.php" ] || problem "lib/serverlist.php was not installed"
cmp -s "$SCRIPTS/install.sh" "$TREE/install.sh" || problem "the installed install.sh is not the tree's"
verdict "handover: the downloaded installer installs with its own file list"
[ "$(grep -c . "$W/fetch.log")" = 1 ] || problem "$(grep -c . "$W/fetch.log") downloads"
[ "$(pcinst)" = "$before" ] || problem "left behind: $(pcinst)"
verdict "handover: one download, removed afterwards"

h0=$(md5s)
before=$(pcinst)
out=$(installed_install "$W/good.tgz" fail); rc=$?
[ "$rc" != 0 ] || problem "exit 0 although the download failed"
[ "$(pcinst)" = "$before" ] || problem "left behind: $(pcinst)"
[ "$(md5s)" = "$h0" ] || problem "installed files changed"
verdict "handover: a failed download changes nothing and leaves nothing"

before=$(pcinst)
out=$(installed_install "$W/bad.tgz"); rc=$?
[ "$rc" = 1 ] || problem "exit $rc"
printf '%s\n' "$out" |
    grep -qxF 'missing from source: src/opnsense/scripts/OPNsense/ParentalControl/lib/firewall.php' ||
    problem "said: $(oneline "$out")"
[ "$(md5s)" = "$h0" ] || problem "installed files changed"
[ "$(pcinst)" = "$before" ] || problem "left behind: $(pcinst)"
verdict "handover: an archive missing a lib file changes nothing"
repair

# 0.0.2's installer installs from its own list, which has no lib/: the first
# upgrade must leave sync.php saying so, and the second must complete it
if [ -z "$OLD" ]; then
    skip "upgrade from 0.0.2" "no 0.0.2 tree given (second argument)"
elif [ ! -f "$OLD/install.sh" ] || [ -d "$OLD/src/opnsense/scripts/OPNsense/ParentalControl/lib" ]; then
    skip "upgrade from 0.0.2" "$OLD is not a 0.0.2 tree"
else
    if ! out=$(sh "$SCRIPTS/uninstall.sh" 2>&1) || ! out=$(sh "$OLD/install.sh" 2>&1); then
        problem "setting up 0.0.2: $(oneline "$out")"
    fi
    out=$(installed_install "$W/good.tgz"); rc=$?
    [ "$rc" = 0 ] || problem "exit $rc: $(oneline "$out")"
    [ ! -e "$SCRIPTS/lib" ] || problem "lib/ was installed"
    verdict "upgrade from 0.0.2: the old installer leaves lib/ out"
    out=$("$SCRIPTS/sync.php" 2>&1); rc=$?
    [ "$rc" = 1 ] || problem "exit $rc"
    [ "$out" = "plugin files incomplete (missing lib/markers.php): run 'configctl parentalcontrol install' again" ] ||
        problem "said: $(oneline "$out")"
    verdict "upgrade from 0.0.2: sync names the missing file"
    out=$(installed_install "$W/good.tgz"); rc=$?
    [ "$rc" = 0 ] || problem "exit $rc: $(oneline "$out")"
    _m=$(missing_files)
    [ -z "$_m" ] || problem "missing: $_m"
    s=$(csync)
    [ "$s" = OK ] || problem "sync: $s"
    verdict "upgrade from 0.0.2: a second install completes it"
    repair
fi

# ---- 3. configure -------------------------------------------------------------

section "3 configure"
out=$($PC general enabled 1 2>&1) || problem "enable: $(oneline "$out")"
out=$($PC add vm-test "$DEV" on 2>&1) || problem "add: $(oneline "$out")"
s=$(csync)
[ "$s" = OK ] || problem "sync: $s"
verdict "configure: enabled, vm-test added, sync answers OK"

h0=$(md5 -q "$CONF")
for name in NoMessaging Messaging_Servers; do
    out=$($PC alias-ok "$name" 2>&1) && problem "accepted"
    printf '%s' "$out" | grep -qF "$name" || problem "said: $(oneline "$out")"
    verdict "configure: alias name $name rejected by the model"
done
out=$($PC alias-ok NoInternet2 2>&1) || problem "rejected: $(oneline "$out")"
[ "$(md5 -q "$CONF")" = "$h0" ] || problem "config.xml changed"
verdict "configure: an ordinary alias name accepted, nothing saved"

# ---- 4. enforced --------------------------------------------------------------

section "4 enforced"
listed NoMessaging "$DEV" || problem "NoMessaging: $(tbl NoMessaging | tr '\n' ' ')"
verdict "enforced: $DEV in NoMessaging"
apple=$($PC first apple_ranges)        # Apple's first range in messaging.json
{ [ -n "$apple" ] && listed Messaging_Servers "$apple"; } || problem "Apple's first range '$apple' not listed"
[ "$(count Messaging_Servers)" -ge 2 ] || problem "$(count Messaging_Servers) entries"
verdict "enforced: Messaging_Servers holds Apple's first range ($apple) and more"
for m in 'Parental Control: block messaging' 'Parental Control: block notification ports'; do
    grep -qF -- "$m" /tmp/rules.debug || problem "no '$m'"
done
verdict "enforced: both messaging rules in /tmp/rules.debug"
ST=$(configctl parentalcontrol status 2>&1)
sget() { printf '%s' "$ST" | $PC jget "$1"; }
[ "$(sget devices.0.messaging_blocked)" = true ] || problem "messaging_blocked: $(sget devices.0.messaging_blocked)"
verdict "enforced: status says vm-test is denied messaging"
[ "$(sget messaging.in_use)" = true ] || problem "in_use: $(sget messaging.in_use)"
[ "$(sget messaging.error)" = null ] || problem "error: $(sget messaging.error)"
case $(sget messaging.refreshed_at) in ''|*[!0-9]*) problem "refreshed_at: $(sget messaging.refreshed_at)" ;; esac
verdict "enforced: status in use, refreshed, no error (lookups ran through the VM's resolver)"

# Two more syncs. pfctl lists a /32 bare; a list compared as written would delete
# every WNS /32 on one run and add it back on the next. A newly learned address
# is the one change allowed, and the second sync reports it.
calm
o1=$("$SCRIPTS/sync.php" 2>&1); r1=$?
n1=$(count Messaging_Servers)
o2=$("$SCRIPTS/sync.php" 2>&1); r2=$?
n2=$(count Messaging_Servers)
line=$(printf '%s\n' "$o2" | grep '^messaging servers=')
added=$(printf '%s' "$line" | sed -n 's/.* added=\([0-9]*\) .*/\1/p')
removed=$(printf '%s' "$line" | sed -n 's/.* removed=\([0-9]*\)$/\1/p')
[ "$r1" = 0 ] && [ "$r2" = 0 ] || problem "exits $r1 and $r2: $(oneline "$o1$o2")"
if [ -z "$line" ]; then
    problem "the second sync did not refresh: $(oneline "$o2")"
elif [ "$removed" != 0 ] || [ "$n2" -ne $((n1 + ${added:-0})) ]; then
    problem "$n1 entries, then $n2; second sync: $line"
fi
verdict "server list: same count across two syncs, apart from what the second learned"
wns=$($PC wns 3)
[ -n "$wns" ] || problem "messaging.json has no /32 WNS entry"
for ip in $wns; do
    listed Messaging_Servers "$ip" || problem "$ip not listed"
done
verdict "server list: WNS /32 entries listed bare"
ST=$(configctl parentalcontrol status 2>&1)
case $(sget messaging.errors) in *"range download failed"*) problem "errors: $(sget messaging.errors)" ;; esac
fetched=$($PC jget published.telegram.fetched_at < "$DATA/messaging-cache.json")
case $fetched in ''|0|*[!0-9]*) problem "cache published.telegram.fetched_at: $fetched" ;; esac
verdict "server list: Telegram's ranges downloaded"
[ "$(php -r 'echo extension_loaded("curl") ? 1 : 0;')" = 1 ] || problem "php's curl extension is not loaded"
verdict "server list: curl extension loaded"
mode=$(stat -f %Lp "$DATA/messaging-cache.json" 2>&1)
[ "$mode" = 600 ] || problem "mode $mode"
verdict "server list: cache file mode 600"

# Device targets in pf's own form: host bits cleared, a /32 bare.
{ out=$($PC add vm-net 192.0.2.20/24 on 2>&1) && out=$($PC add vm-host 192.0.2.30/32 on 2>&1); } ||
    problem "add: $(oneline "$out")"
calm
o1=$("$SCRIPTS/sync.php" 2>&1)
a1=0; listed NoMessaging 192.0.2.0/24 && a1=1
b1=0; listed NoMessaging 192.0.2.30 && b1=1
o2=$("$SCRIPTS/sync.php" 2>&1)
{ [ "$a1" = 1 ] && listed NoMessaging 192.0.2.0/24; } ||
    problem "192.0.2.0/24 missing after a sync: $(tbl NoMessaging | tr '\n' ' '); first sync: $(oneline "$o1")"
line=$(printf '%s\n' "$o2" | grep '^messaging blocked=')
[ "$line" = "messaging blocked=3 added=0 removed=0" ] || problem "second sync: $(oneline "$o2")"
verdict "canonical targets: 192.0.2.20/24 kept as 192.0.2.0/24 across two syncs"
{ [ "$b1" = 1 ] && listed NoMessaging 192.0.2.30; } || problem "192.0.2.30 missing: $(tbl NoMessaging | tr '\n' ' ')"
verdict "canonical targets: 192.0.2.30/32 listed bare"
{ $PC del vm-net > /dev/null 2>&1 && $PC del vm-host > /dev/null 2>&1; } || problem "cannot delete the devices"
s=$(csync)
[ "$s" = OK ] || problem "sync: $s"
[ "$(tbl NoMessaging)" = "$DEV" ] || problem "NoMessaging: $(tbl NoMessaging | tr '\n' ' ')"
verdict "canonical targets: gone again with their devices"

# A state is dropped when its device becomes blocked. On a lone VM the device is
# the VM itself: its own connections leave from its own address. The floating
# rules match only packets coming in, so blocking that address cuts nothing else.
# The target is the first notification name in messaging.json, one of Google's,
# on 443: every refresh looks it up whichever apps are selected, so its addresses
# are in the list and the connection is picked by destination. The control goes
# to a server outside the list.
CONNS=
conn() {                    # conn NAME HOST PORT: hold a connection; sets C_LOCAL and C_REMOTE
    rm -f "$W/conn-$1"
    $PC hold-conn "$2" "$3" 300 "$W/conn-$1" &
    track "$!"
    CONNS="$CONNS $!"
    wait_file "$W/conn-$1" 15 && read -r C_LOCAL C_REMOTE _rest < "$W/conn-$1" && [ "$C_LOCAL" != fail ]
}
push=$($PC first google_hosts)
if [ -z "$push" ] || ! conn target "$push" 443; then
    skip "drops" "no connection to ${push:-the first notification name}:443: $(cat "$W/conn-target" 2>/dev/null)"
elif ! pfctl -t Messaging_Servers -T test "${C_REMOTE%:*}" > /dev/null 2>&1; then
    skip "drops" "${C_REMOTE%:*} ($push) is not in Messaging_Servers"
elif ! has_state "$C_LOCAL"; then
    skip "drops" "pf shows no state for the VM's own connection $C_LOCAL"
else
    T_LOCAL=$C_LOCAL
    T_REMOTE=$C_REMOTE
    VMIP=${T_LOCAL%:*}
    CTL=
    if conn control codeload.github.com 443 &&
        ! pfctl -t Messaging_Servers -T test "${C_REMOTE%:*}" > /dev/null 2>&1 && has_state "$C_LOCAL"; then
        CTL=$C_LOCAL
    fi
    out=$($PC add vm-self "$VMIP" on 2>&1) || problem "add: $(oneline "$out")"
    s=$(csync)
    [ "$s" = OK ] || problem "sync: $s"
    ! has_state "$T_LOCAL" || problem "the state $T_LOCAL -> $T_REMOTE is still there"
    verdict "drops: a newly blocked device loses its connection to Messaging_Servers"
    if [ -n "$CTL" ]; then
        has_state "$CTL" || problem "the state $CTL to codeload.github.com was dropped too"
        verdict "drops: its other connections stay"
    else
        skip "drops: its other connections stay" "no control connection to codeload.github.com:443"
    fi
    out=$($PC del vm-self 2>&1) || problem "delete: $(oneline "$out")"
    s=$(csync)
    [ "$s" = OK ] || problem "sync: $s"
    ! listed NoMessaging "$VMIP" || problem "$VMIP still in NoMessaging"
    verdict "drops: the VM's address leaves NoMessaging again"
fi
for _p in $CONNS; do stop "$_p"; done

# A dead resolver must not hold a sync up: one try of one second per name, and
# a time budget (lib/serverlist.php). resolv.conf is replaced only once a copy
# is saved, which the trap also restores.
calm
if cp -p /etc/resolv.conf "$W/resolv.conf.orig"; then
    printf 'nameserver 192.0.2.1\n' > /etc/resolv.conf
    t0=$(date +%s)
    out=$("$SCRIPTS/sync.php" 2>&1); rc=$?
    took=$(($(date +%s) - t0))
    ST=$(configctl parentalcontrol status 2>&1)
    cp -p "$W/resolv.conf.orig" /etc/resolv.conf && rm -f "$W/resolv.conf.orig"
    [ "$rc" = 0 ] || problem "exit $rc: $(oneline "$out")"
    [ "$took" -le 60 ] || problem "longer than 60 s"
    verdict "resolver down: sync exits 0 within 60 s (took $took s)"
    case $(sget messaging.errors) in
        *"lookup failed: "*|*"lookup skipped (time budget): "*) ;;
        *) problem "errors: $(sget messaging.errors)" ;;
    esac
    verdict "resolver down: status reports the failed lookups"
else
    rm -f "$W/resolv.conf.orig"        # a partial copy: the trap must not put it back
    fail "resolver down: sync exits 0 within 60 s" "could not save a copy of /etc/resolv.conf, so it was left alone"
    skip "resolver down: status reports the failed lookups" "no sync ran with the resolver down"
fi
calm
csync > /dev/null                      # learn again through the working resolver

# ---- 5. schedule --------------------------------------------------------------

section "5 schedule"
w=$($PC window vm-test -60 60 2>&1) || problem "window: $(oneline "$w")"
s=$(csync)
[ "$s" = OK ] || problem "sync: $s"
! listed NoMessaging "$DEV" || problem "$DEV still in NoMessaging"
verdict "schedule: inside the allowed window ($w) messaging is not blocked"
w=$($PC window vm-test 60 120 2>&1) || problem "window: $(oneline "$w")"
s=$(csync)
[ "$s" = OK ] || problem "sync: $s"
listed NoMessaging "$DEV" || problem "$DEV not in NoMessaging"
verdict "schedule: outside the allowed window ($w) messaging is blocked"
out=$($PC set vm-test messaging off 2>&1) || problem "set: $(oneline "$out")"
s=$(csync)
[ "$s" = OK ] || problem "sync: $s"
! listed NoMessaging "$DEV" || problem "$DEV still in NoMessaging"
verdict "schedule: messaging off is not blocked"
ST=$(configctl parentalcontrol status 2>&1)
[ "$(sget messaging.in_use)" = false ] || problem "in_use: $(sget messaging.in_use)"
[ "$(sget messaging.refreshed_at)" = null ] || problem "refreshed_at: $(sget messaging.refreshed_at)"
verdict "schedule: status says messaging is not in use"

# ---- 6. survives reload -------------------------------------------------------

section "6 survives reload"
n1=$(count Messaging_Servers)
out=$(configctl filter reload 2>&1)
n2=$(count Messaging_Servers)
[ "$n1" -gt 0 ] || problem "Messaging_Servers was empty"
[ "$out" = OK ] || problem "filter reload: $(oneline "$out")"
[ "$n1" = "$n2" ] || problem "$n1 entries before the reload, $n2 after"
verdict "survives reload: Messaging_Servers keeps its entries through a filter reload"

# ---- 6b. uninstall guards -----------------------------------------------------
# The plugin stays installed and configured through this section.

section "6b uninstall guards"
out=$($PC set vm-test messaging on 2>&1) || problem "set: $(oneline "$out")"
s=$(csync)
[ "$s" = OK ] || problem "sync: $s"
in_place
verdict "guards: messaging on again, everything in place"

h0=$(md5 -q "$CONF")
for args in --finale '--final --final' x; do
    # shellcheck disable=SC2086 # split on purpose: '--final --final' is two arguments
    out=$("$SCRIPTS/uninstall.php" $args 2>&1); rc=$?
    [ "$rc" = 1 ] || problem "exit $rc"
    [ "$out" = 'usage: uninstall.php [--final]' ] || problem "said: $(oneline "$out")"
    [ "$(md5 -q "$CONF")" = "$h0" ] || problem "config.xml changed"
    [ ! -e "$MARKER" ] || problem "marker left"
    verdict "usage: uninstall.php $args refused, nothing changed"
done

# a lib file missing: uninstall.php says which, uninstall.sh removes nothing
mv "$SCRIPTS/lib/messaging.php" "$W/messaging.php.aside"
want="plugin files incomplete (missing lib/messaging.php): run 'configctl parentalcontrol install' again"
out=$("$SCRIPTS/uninstall.php" 2>&1); rc=$?
[ "$rc" = 1 ] || problem "exit $rc"
[ "$out" = "$want" ] || problem "said: $(oneline "$out")"
verdict "missing lib: uninstall.php names lib/messaging.php"
out=$(sh "$SCRIPTS/uninstall.sh" 2>&1); rc=$?
[ "$rc" = 1 ] || problem "exit $rc"
for l in "$want" 'stopped: no file was removed'; do
    printf '%s\n' "$out" | grep -qxF -- "$l" || problem "no line '$l'"
done
_m=$(missing_files | sed "s#$SCRIPTS/lib/messaging.php ##")
[ -z "$_m" ] || problem "files removed: $_m"
[ "$(md5 -q "$CONF")" = "$h0" ] || problem "config.xml changed"
[ ! -e "$MARKER" ] || problem "marker left"
verdict "missing lib: uninstall.sh stops and removes nothing"
mv "$W/messaging.php.aside" "$SCRIPTS/lib/messaging.php"

# uninstall takes the sync lock, and gives up after 30 s
calm
hold_lock 40 || problem "could not take the lock"
t0=$(date +%s)
out=$("$SCRIPTS/uninstall.php" --final 2>&1); rc=$?
took=$(($(date +%s) - t0))
release
[ "$rc" = 1 ] || problem "exit $rc"
printf '%s\n' "$out" | grep -qxF 'not removing anything: a sync is still running' || problem "said: $(oneline "$out")"
printf '%s\n' "$out" | grep -qxF "$WAITING" || problem "no line '$WAITING'"
[ "$took" -ge 29 ] && [ "$took" -le 40 ] || problem "gave up after $took s"
[ "$(md5 -q "$CONF")" = "$h0" ] || problem "config.xml changed"
[ ! -e "$MARKER" ] || problem "marker left"
verdict "lock: uninstall gives up after 30 s and changes nothing"

# a signal during the wait: exit 1, and the marker goes
calm
hold_lock 60 || problem "could not take the lock"
for sig in TERM HUP INT; do
    rm -f "$W/u.out"
    "$SCRIPTS/uninstall.php" --final > "$W/u.out" 2>&1 &
    upid=$!
    track "$upid"
    wait_file "$W/u.out" 5 "$WAITING" || problem "not waiting: $(oneline "$(cat "$W/u.out")")"
    [ -e "$MARKER" ] || problem "no marker while waiting"
    kill -s "$sig" "$upid"
    reap "$upid"; rc=$?
    [ "$rc" = 1 ] || problem "exit $rc"
    [ ! -e "$MARKER" ] || problem "marker left"
    [ "$(md5 -q "$CONF")" = "$h0" ] || problem "config.xml changed"
    verdict "signal $sig during the wait: exit 1, no marker, nothing changed"
done
release
s=$(csync)
[ "$s" = OK ] || problem "sync: $s"
in_place
verdict "signals: the next sync applies, everything in place"

# uninstall waits for a running sync, reads the config again, then removes
calm
hold_lock 5 || problem "could not take the lock"
t0=$(date +%s)
out=$("$SCRIPTS/uninstall.php" 2>&1); rc=$?
took=$(($(date +%s) - t0))
release
[ "$rc" = 0 ] || problem "exit $rc: $(oneline "$out")"
[ "$took" -ge 4 ] || problem "did not wait ($took s)"
printf '%s\n' "$out" | grep -qxF "$WAITING" || problem "no line '$WAITING'"
printf '%s\n' "$out" | grep -qxF 'alias NoMessaging' || problem "removed: $(oneline "$out")"
[ ! -e "$MARKER" ] || problem "marker left without --final"
verdict "lock: uninstall waits for a running sync, then removes"
s=$(csync)
[ "$s" = OK ] || problem "sync: $s"
in_place
verdict "lock: the next sync puts everything back"

# configd cannot pass --final, so its uninstall leaves no marker and the next
# sync puts everything back: the spec's step-5 rehearsal. configctl itself
# rejects a bare --final (argparse: unrecognized arguments); after "--" it reaches
# configd, whose action has no %s in its parameters and so drops it.
calm
out=$(configctl parentalcontrol uninstall -- --final 2>&1)
printf '%s\n' "$out" | grep -qxF 'alias NoMessaging' || problem "said: $(oneline "$out")"
[ ! -e "$MARKER" ] || problem "marker left"
[ "$(plugin_lines)" = 0 ] || problem "$(plugin_lines) 'Parental Control:' lines left"
verdict "configd uninstall --final: objects removed, no marker"
s=$(csync)
[ "$s" = OK ] || problem "sync: $s"
in_place
ST=$(configctl parentalcontrol status 2>&1)
[ "$(sget cron)" = '"enabled"' ] || problem "cron: $(sget cron)"
for m in 'Parental Control: block internet' 'Parental Control: block messaging' 'Parental Control: block notification ports'; do
    grep -qF -- "$m" /tmp/rules.debug || problem "no '$m' in /tmp/rules.debug"
done
verdict "configd uninstall: the next sync puts tables, cron job and rules back"
# the external tables and the objects in config.xml; the _Local table is left
# out because core refreshes network aliases in the background after a reload
inventory() { pfctl -s Tables 2>/dev/null | grep -xE 'NoInternet|NoMessaging|Messaging_Servers' | sort; plugin_lines; }
inv0=$(inventory)
calm
out=$(configctl parentalcontrol uninstall 2>&1)
s=$(csync)
[ ! -e "$MARKER" ] || problem "marker left"
[ "$s" = OK ] || problem "sync: $s"
[ "$(inventory)" = "$inv0" ] || problem "before: $(oneline "$inv0"); after: $(oneline "$(inventory)")"
in_place
verdict "step-5 rehearsal: uninstall then sync leaves the same inventory"

# a sync that waited behind uninstall.php --final changes nothing
calm
hold_lock 5 || problem "could not take the lock"
rm -f "$W/u.out"
"$SCRIPTS/uninstall.php" --final > "$W/u.out" 2>&1 &
upid=$!
track "$upid"
wait_file "$W/u.out" 4 "$WAITING" || problem "uninstall.php is not waiting"
out=$("$SCRIPTS/sync.php" 2>&1); rc=$?
reap "$upid"; urc=$?
release
[ "$rc" = 0 ] || problem "exit $rc"
[ "$out" = "$PAUSED" ] || problem "said: $(oneline "$out")"
verdict "marker: a sync during uninstall --final changes nothing"
[ "$urc" = 0 ] || problem "uninstall exit $urc: $(oneline "$(cat "$W/u.out")")"
grep -qxF 'alias NoMessaging' "$W/u.out" || problem "removed: $(oneline "$(cat "$W/u.out")")"
[ "$(plugin_lines)" = 0 ] || problem "$(plugin_lines) 'Parental Control:' lines left"
for t in NoInternet NoMessaging Messaging_Servers; do
    [ -z "$(tbl "$t")" ] || problem "$t still has entries"
done
[ -e "$MARKER" ] || problem "no marker"
verdict "marker: uninstall --final removed everything and left the marker"
s=$(csync)
[ "$s" = OK ] || problem "sync: $s"
[ "$(plugin_lines)" = 0 ] || problem "objects re-created"
[ -z "$(tbl NoMessaging)" ] || problem "NoMessaging refilled"
verdict "marker: configctl sync answers OK and re-creates nothing"
[ "$(configctl parentalcontrol status 2>&1 | $PC jget devices.0.name)" = '"vm-test"' ] || problem "no status"
verdict "marker: status still answers"
out=$(sh "$TREE/install.sh" 2>&1) || problem "reinstall: $(oneline "$out")"
[ ! -e "$MARKER" ] || problem "marker still there"
s=$(csync)
[ "$s" = OK ] || problem "sync: $s"
in_place
verdict "marker: a reinstall clears it and the next sync restores everything"

# A signal after uninstall.php --final has saved (timing-dependent): exit 1, no
# marker, and the next sync puts back what was removed. A signal inside the save
# can leave config.xml empty; core then restores the newest backup on the next
# load, which is accepted.
calm
rm -f "$W/u.out"
"$SCRIPTS/uninstall.php" --final > "$W/u.out" 2>&1 &
upid=$!
track "$upid"
sent=$($PC poll-signal "$upid")
reap "$upid"; rc=$?
restore=
if [ "$sent" = timeout ]; then
    fail "signal after the save" "config.xml never changed: $(oneline "$(cat "$W/u.out")")"
    restore=1
elif [ "$rc" = 0 ]; then
    skip "signal after the save" "uninstall.php finished before the signal arrived ($sent)"
    restore=1
elif [ "$rc" = 1 ] && [ -e "$MARKER" ]; then
    skip "signal after the save" "the signal arrived after the last step"
    restore=1
elif [ "$rc" = 1 ]; then
    [ -s "$CONF" ] || echo "  note: config.xml was left empty; core restores the newest backup on the next load"
    pass "signal after the save: exit 1, no marker"
else
    fail "signal after the save" "exit $rc: $(oneline "$(cat "$W/u.out")")"
    restore=1
fi
[ -z "$restore" ] || out=$(sh "$TREE/install.sh" 2>&1)
s=$(csync)
[ "$s" = OK ] || problem "sync: $s"
in_place
verdict "signal after the save: the next sync puts everything back"

# ---- 7. uninstall keeps settings ----------------------------------------------

section "7 uninstall keeps settings"
calm
out=$(sh "$SCRIPTS/uninstall.sh" 2>&1); rc=$?
[ "$rc" = 0 ] || problem "exit $rc: $(oneline "$out")"
verdict "uninstall: uninstall.sh exits 0"
tables_gone "uninstall" NoInternet
grep -qF '<name>vm-test</name>' "$CONF" || problem "vm-test is gone from config.xml"
verdict "uninstall: device settings kept"
removed_checks "uninstall" plugin

# ---- 8. refuses when referenced -----------------------------------------------

section "8 refuses when referenced"
out=$(sh "$TREE/install.sh" 2>&1) || problem "reinstall: $(oneline "$out")"
# the objects are made on first use, so messaging must be on before a user rule
# can name NoMessaging
out=$($PC set vm-test messaging on 2>&1) || problem "set: $(oneline "$out")"
s=$(csync)
[ "$s" = OK ] || problem "sync: $s"
in_place
verdict "refusal: reinstalled, messaging on, sync answers OK"
uuid=$($PC rule-add 'vm-test user rule' NoMessaging 2>&1)
case $uuid in
    ''|*[!0-9a-f-]*) problem "not added: $(oneline "$uuid")" ;;
    *) RULE=$uuid ;;                    # the trap deletes it if the run stops early
esac
verdict "refusal: a user's floating rule with source NoMessaging added"
h0=$(md5 -q "$CONF")
calm
out=$(sh "$SCRIPTS/uninstall.sh" 2>&1); rc=$?
[ "$rc" = 1 ] || problem "exit $rc"
for l in "not removing anything: these refer to the plugin's aliases:" \
         "OPNsense/Firewall/Filter/rules/rule/source_net ($uuid)" 'stopped: no file was removed'; do
    printf '%s\n' "$out" | grep -qxF -- "$l" || problem "no line '$l'"
done
[ -n "$P" ] && problem "said: $(oneline "$out")"
verdict "refusal: uninstall.sh exits 1 and names the rule"
[ "$(md5 -q "$CONF")" = "$h0" ] || problem "config.xml changed"
verdict "refusal: config.xml unchanged"
_m=$(missing_files)
[ -z "$_m" ] || problem "files removed: $_m"
in_place
verdict "refusal: files, aliases, rules and table entries kept"
[ ! -e "$MARKER" ] || problem "marker left"
verdict "refusal: no uninstall marker left"
s=$(csync)
[ "$s" = OK ] || problem "sync: $s"
in_place
verdict "refusal: the next sync applies, everything in place"
if out=$($PC rule-del "$uuid" 2>&1); then RULE=; else problem "$(oneline "$out")"; fi
! grep -qF 'vm-test user rule' "$CONF" || problem "still in config.xml"
verdict "refusal: the user rule deleted"

# ---- 8b. uninstall during a sync ----------------------------------------------
# with uninstall.sh: syncs that waited behind uninstall.php change nothing, whether
# they find the marker or their own script gone

section "8b uninstall during a sync"
# a refusal that failed above has removed the plugin: put it back first
if [ ! -f "$SCRIPTS/uninstall.sh" ]; then
    sh "$TREE/install.sh" > /dev/null 2>&1
    csync > /dev/null
fi
calm
hold_lock 5 || problem "could not take the lock"
rm -f "$W/u.out"
sh "$SCRIPTS/uninstall.sh" > "$W/u.out" 2>&1 &
USH=$!                      # not a helper the trap kills: see cleanup
wait_file "$W/u.out" 4 "$WAITING" || problem "uninstall.php is not waiting"
out=$("$SCRIPTS/sync.php" 2>&1); rc=$?
wait "$USH"; urc=$?
USH=
release
[ "$rc" = 0 ] || problem "exit $rc"
[ "$out" = "$PAUSED" ] || problem "said: $(oneline "$out")"
verdict "uninstall during a sync: a sync that waited changes nothing"
[ "$urc" = 0 ] || problem "exit $urc: $(oneline "$(cat "$W/u.out")")"
verdict "uninstall during a sync: uninstall.sh exits 0"
tables_gone "uninstall during a sync" NoInternet
removed_checks "uninstall during a sync" plugin

# ---- 9. purge -----------------------------------------------------------------
# with the internet alias named "sync", a word of the plugin's own cron job

section "9 purge"
out=$(sh "$TREE/install.sh" 2>&1) || problem "reinstall: $(oneline "$out")"
out=$($PC general alias_name sync 2>&1) || problem "rename: $(oneline "$out")"
s=$(csync)
[ "$s" = OK ] || problem "sync: $s"
[ "$(configctl parentalcontrol status 2>&1 | $PC jget alias)" = '"sync"' ] || problem "status alias is not sync"
grep -qF '<name>sync</name>' "$CONF" || problem "no alias named sync in config.xml"
verdict "purge: reinstalled, internet alias named sync"
calm
out=$(sh "$SCRIPTS/uninstall.sh" --purge 2>&1); rc=$?
[ "$rc" = 0 ] || problem "exit $rc: $(oneline "$out")"
! printf '%s\n' "$out" | grep -qF 'OPNsense/cron/jobs/job/command' || problem "refused over the cron job's command"
printf '%s\n' "$out" | grep -qF 'device settings removed' || problem "said: $(oneline "$out")"
verdict "purge: uninstall.sh --purge exits 0 with no refusal"
tables_gone "purge" sync
! grep -q '<ParentalControl' "$CONF" || problem "settings left in config.xml"
verdict "purge: settings removed"
removed_checks "purge"

finish
