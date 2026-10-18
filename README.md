# os-parentalcontrol

Turn a device's internet access on or off, on a schedule or on demand. A second,
narrower switch blocks just a device's messaging apps and leaves the rest of its
internet alone.

Adds **Firewall → Parental Control** and a dashboard widget with per-device
switches. Blocked devices lose *internet only* — printers, media players, NAS
and local dashboards keep working.

## How it enforces

Two aliases and one rule: an externally-managed alias holding the addresses
currently denied internet, a companion alias defining private address space, and
one floating rule blocking the first to anything outside the second. Toggling a device is a pf table update, so it takes effect immediately
with no ruleset reload.

Per-device rules were rejected deliberately: they do not scale, they apply
slowly, and arbitrary per-device schedules would need one rule per distinct
schedule. One alias and one rule stays comprehensible at any number of devices.

Both aliases and the rule are created automatically on first apply and are
idempotent. The plugin recognises its own objects by a description marker rather
than by name, so your existing rules are never touched and nothing is duplicated.

**Renaming the alias is safe.** Because the marker is name-independent, changing
`Alias name` renames the existing aliases in place and repoints the rule at them,
rather than creating a second pair and leaving the first enforcing invisibly. The
old pf table is flushed on the way through, so a device that happened to be
blocked at the moment of the rename does not stay blocked by a table nothing
maintains any more. Renaming an alias by hand in Firewall → Aliases is picked up
the same way on the next sync. An empty alias means the rule matches
nothing, so the plugin is inert until you add a device.

**Messaging adds two more tables and two more rules**, made the same way:

- `NoMessaging`, an externally-managed alias holding the addresses of the devices
  currently denied messaging
- `Messaging_Servers`, an externally-managed alias holding what those devices may
  not reach: the selected apps' servers and the notification services' addresses
- a floating rule blocking `NoMessaging` to `Messaging_Servers`
- a floating rule blocking `NoMessaging` to any destination on TCP ports 5228 to
  5230, which Google reserves for its notification service

Toggling a device is again a pf table update, with no ruleset reload. The four
objects are made the first time a device's Messaging setting is anything but Not
blocked, so a firewall that never uses it gains nothing, and they are found by
description marker like the rest. Unlike the internet alias, their names are fixed. If an alias with one of those
names already exists and the plugin did not make it, the plugin does not take it
over: messaging blocking stays off, the Status tab says why, and the internet
switch keeps working. For the same reason the internet alias itself cannot be
named `NoMessaging` or `Messaging_Servers`.

## Blocking messaging

Each device has a **Messaging** setting, separate from its internet mode:

- **Not blocked**, the default, also for devices saved by an earlier release
- **Blocked**, always
- **Blocked outside allowed hours**, following the device's *Days* and *Internet
  allowed from / until* fields

The third follows those fields exactly as the internet schedule does, windows that
cross midnight included, and keeps following them when *Override* is *Force
allow*. Mode and Override steer only the internet switch and never change the
messaging decision. A device whose internet is blocked cannot use its messaging
apps either, but the Status tab still shows the messaging decision as computed.

A device *uses messaging* when the plugin and the device are enabled and its
Messaging setting is anything but Not blocked, whatever its schedule says at that
moment. Until one does, nothing is looked up or downloaded and the messaging
objects are not made.

Which apps count is one list for every device: **Messaging apps to block** under
Settings, with every app offered below selected by default. Blocked means
completely blocked: the selected apps stop sending, receiving and calling, since
calls are set up through the same servers as chats. Deselecting an app unblocks
it within a minute. Deselecting all of them is allowed, and leaves only the
notification cut described next.

### Notifications

A phone receives every app's notifications over one shared connection to its
platform's notification service, so the network cannot take away one app's message
previews and keep the rest. While a device is denied messaging the plugin
therefore also blocks the notification services themselves, **whichever apps are
selected, even none**:

- Apple's push service: all traffic to Apple's published ranges
- Google's (Firebase Cloud Messaging): TCP ports 5228 to 5230 to any destination,
  and all traffic to the addresses of Google's `mtalk` names, which covers the
  port 443 fallback
- Microsoft's Windows Notification Service: all traffic to the addresses in
  Microsoft's published list

**That also stops iMessage and incoming FaceTime calls on that device**, because
both depend on Apple's notification service, along with every other app's
notifications, for as long as the block lasts. Ordinary phone calls and SMS over
the mobile network still work. There is no switch for this: it is part of every
messaging block. Notifications held back may all arrive when it lifts.

Apart from that, nothing else on the device is touched: web browsing, video,
iCloud, the app stores, school and work tools such as Teams, and Facebook and
Instagram, which are not on the list, keep working.

### The apps

Offered: **WhatsApp, Telegram, Snapchat, Signal and LINE**. An app is offered only
where its servers are its own, so that blocking them takes nothing unrelated with
it. That is why Snapchat's and Signal's content-delivery names, which sit on shared
front ends, are left out of their lists.

Not offered, and why:

| App | Why not |
|---|---|
| Messenger | its servers share an address with Facebook's Graph API, which the Facebook app and "Log in with Facebook" in other apps rely on |
| Discord | it sits behind Cloudflare's shared front end |
| Kik | it sits behind Fastly's shared front end |
| WeChat | its servers are Tencent's shared infrastructure |
| Viber | its chat servers could not be identified |

Facebook's and Instagram's direct messages cannot be blocked on their own either:
their chat runs on the same servers as the rest of each app, so the network could
only block the whole app. Teams, Skype and Slack are not on the list.

### Where the block happens

By destination address, not by name. On many home networks a device's DNS lookups
never pass through the firewall (a DNS filter on the same segment, for instance),
so a policy based on names would only ever see that filter. What the firewall
always sees is where a device's traffic goes. So the plugin keeps its own list of
the selected apps' server addresses, and works without Pi-hole or any other DNS
filter, depending on nothing outside OPNsense core:

- **Names to addresses.** The apps' server names are in a data file shipped with
  the plugin, `messaging.json`. While some device uses messaging they are
  resolved every minute through the firewall's own resolver, the notification
  services' names first so that a slow resolver cannot starve them. Lookups are
  limited in time, and the names not reached are reported.
- **Remembering.** An address is kept for **7 days** after it was last seen,
  because the apps' names rotate through pools of short-lived addresses. The list
  survives a reboot: it lives in `/var/db/parentalcontrol`, outside `config.xml`.
- **Public addresses only.** Only public IPv4 answers are learned, so a resolver
  that sinkholes a name with `0.0.0.0` or a local address cannot get a device's
  own network blocked.
- **Published ranges.** Telegram's are downloaded from core.telegram.org once a
  day, with an hour's wait after a failed download. The last good copy, then the
  copy shipped with the plugin, stand in for a download that fails. A downloaded
  range wider than a /8 is ignored.
- **Never emptied.** A failed lookup or download does not empty the list, and the
  errors show in Status.
- **Protected names.** A list of names that must never be blocked (Facebook and
  Instagram, iCloud, Google, Microsoft sign-in and the like) is resolved on every
  run, and an address one of them resolves to is never learned. Neither is any
  address inside a shared-CDN range (Cloudflare, Fastly, Akamai, Amazon
  CloudFront); those ranges ship with the plugin, so this guard works offline.
  Addresses already learned are checked again every run. If a protected name
  cannot be resolved and nothing recent is remembered for it, learning pauses: the
  plugin cannot tell whether an address it finds is shared with that name, so it
  learns nothing new until the name resolves again, and Status says "learning
  paused: no answer for …".

A change takes effect on Apply or within the one-minute cycle.

### Status

The Status tab gains a **Messaging** column per device (blocked or allowed, with
the reason) and a summary line: "Messaging: not in use" while no enabled device
uses messaging; "Messaging: N server entries · not refreshed yet" until the first
refresh has run (Apply runs a sync, which refreshes at once); then "· refreshed
HH:MM". Behind it come any lookup or download errors and, in red, why messaging
cannot be enforced at all: a reserved name taken by someone else's alias, a missing
object, or an unreadable data file. `configctl parentalcontrol status` carries the
same under a `messaging` key, and per device `messaging`, `messaging_blocked` and
`messaging_reason`. The existing keys are unchanged, which is what the dashboard
widget reads.

## Addressing a device

The **Address** field takes an IPv4 address, a CIDR, or a MAC address. It is a
plain text box with a suggestion list built from Dnsmasq, so you can type a
value or pick a known device — and what is suggested depends on how the device
is known:

- a device with a **static reservation** suggests its **IP**, because the
  reservation already pins that MAC to that address permanently
- a device seen only in a **lease** suggests its **MAC**, because its address is
  dynamic and would otherwise drift

Picking an entry fills an empty Name field from the device's hostname; an
existing name is never overwritten.

A MAC is resolved to whatever address the device currently holds — from ARP
first, falling back to DHCP leases. This is not layer-2 filtering: pf has no MAC
matching, so a MAC can only ever be turned into an address and filtered on that.
The consequences are worth knowing:

- a device the firewall has not seen cannot be resolved, and the status tab says
  so rather than implying the device is covered (it is also not using the
  internet at that moment, so nothing escapes)
- a change of address is only picked up on the next sync
- a randomised MAC cannot be followed at all

**A static DHCP reservation plus its IP is the more reliable choice**, and is
already MAC-based identity — the reservation pins that MAC to that address
permanently, with no resolution step and no lag. Use a MAC when the device has
no reservation.

A CIDR with host bits set, such as `192.0.2.10/24`, is used in its network form
(`192.0.2.0/24`), and an address entered as `/32` is used as the bare address.
That is how pf keeps and lists them, so the tables match what pf holds instead of
being rewritten on every run, and the `resolved` field of the status output shows
the form actually used.

## Schedules

A device is **always allowed**, **always blocked**, or **scheduled**. A schedule
is the window during which internet is *allowed*, plus the days it applies on —
a bedtime rule is "allowed 07:00–21:00", not "blocked 21:00–07:00". Expressing it
one way round removes the usual ambiguity. A window whose start is later than its
end crosses midnight.

**Override** is separate from the schedule and wins over it. The widget's switch
writes the override, so flicking a device off does not edit its hours; the clock
button clears the override and hands the device back to its schedule. Override
steers only the internet: a device's Messaging setting never looks at it (see
Blocking messaging).

## Why existing connections get dropped

A device that becomes blocked keeps its established connections — an in-progress
stream runs to completion and the block looks broken. With *Drop existing
connections* enabled (the default) their states are dropped, so off means off.

The same setting covers messaging. When a device starts being denied messaging,
only its connections to the servers table and to TCP ports 5228 to 5230 are
dropped, never its other connections nor any other device's, so an open chat does
not carry on. The notification services hold one long-lived connection per device,
and without this a phone would go on receiving notifications until that connection
happened to close. When a new server address is learned while devices are denied,
their connections to it are dropped too. This is best effort: a failure never
fails the sync.

## Nothing is installation-specific

The block rule has no interface set, so it covers every interface including ones
added later; so do the two messaging rules. "Not the internet" is RFC1918, which
is the definition of private address space rather than an assumption about any
particular network. The alias name is configurable.

## Install

Run once on the firewall as root:

    fetch -qo /tmp/pc.tgz https://codeload.github.com/nycoagung/opnsense-plugin-parentalcontrol/tar.gz/refs/heads/main && \
      rm -rf /tmp/pcx && mkdir -p /tmp/pcx && tar -xzf /tmp/pc.tgz -C /tmp/pcx && \
      sh /tmp/pcx/opnsense-plugin-parentalcontrol-main/install.sh

Sources come from **codeload**, which serves the git ref directly: one request
for the whole tree, no rate limit, and current. The two obvious alternatives
both fail here:

- the **GitHub API** costs one rate-limited request per file (60/hour per IP,
  and it is the firewall's own public IP that counts) — fifteen files exhausted
  it after four installs
- **raw.githubusercontent** is CDN-cached, lags pushes by minutes and is cached
  per edge, so it silently serves stale content. Measured: raw was serving a
  five-commit-old installer at the moment codeload matched the repo byte for byte

Running the script from an already-extracted archive installs from there instead
of downloading again, so the bootstrap above costs exactly one fetch. Run from
anywhere else, as the installed copy is when `configctl` runs it, the script
downloads the archive and hands over to the `install.sh` inside it, so the
installer that does the work is always the downloaded release's own and a release
that adds files installs completely.

Then reload the GUI and open **Firewall → Parental Control**.

## The three configd actions

    configctl parentalcontrol sync        re-evaluate every device, sync the alias
    configctl parentalcontrol install     re-fetch the plugin from upstream
    configctl parentalcontrol uninstall   remove the aliases, rules, pf tables and cron job

They are not interchangeable. `sync` applies device states using the code already
on the firewall; it is the one cron needs. `install` pulls new code and does not
touch device state. `uninstall` removes the firewall objects but leaves the files,
which is the way to stop enforcement without uninstalling — `sync` puts them back.
It has caveats of its own, listed under Uninstall.

## Upgrading from 0.0.2

`configctl parentalcontrol install` runs the installer already on the firewall,
and 0.0.2's only knows the files of 0.0.2. The first run after the upgrade
therefore lays down the new `sync.php` without the library files it needs, and
every sync then stops with

    plugin files incomplete (missing lib/markers.php): run 'configctl parentalcontrol install' again

so schedules are not re-evaluated, and devices stay as they were, until the
install runs **a second time**. The first run also copies the new installer, which
does the rest. Run `configctl parentalcontrol install` twice in a row, or use the
codeload bootstrap under Install, which runs the new release's installer from the
start. From 0.1.0 on this cannot recur, because an installed installer hands over
to the downloaded release's own.

## Keeping it installed

These files are not owned by a package, so a firmware upgrade can remove them.

- The **every-minute schedule job is created automatically** when you enable the
  plugin, and disabled again when you disable it. It is not optional — schedules
  are only re-evaluated when it runs, so without it the Status tab would show the
  right answer while nothing actually changed. It appears in System → Settings →
  Cron with origin `parentalcontrol`; the plugin owns it, so leave it alone.
- Add cron *Install/refresh Parental Control plugin from upstream* weekly
  yourself, to reinstate the files after an upgrade removes them. When you are
  upgrading from 0.0.2, do not wait for that job: its first run after the upgrade
  is still made by 0.0.2's installer and leaves every sync failing until the run
  after it (see Upgrading from 0.0.2). Install once by hand, twice in a row.

The Status tab flags it in red if that job is ever missing or disabled while the
plugin is enabled, so a silently-inert install is visible rather than something
you discover when a bedtime does not happen.

The installer is safe to run from cron: it only restarts configd when the action
file actually changed, because restarting configd from a script configd launched
would kill that script mid-run.

## Uninstall

    /usr/local/opnsense/scripts/OPNsense/ParentalControl/uninstall.sh

Removes everything the plugin added: the internet alias and its companion, the
two messaging aliases, the three rules (internet, messaging and notification
ports), the cron job, the three pf tables, the plugin's data directory
(`/var/db/parentalcontrol`, which holds the learned server list) and the files.
Order matters: the tables are flushed and the rules deleted before the aliases they
reference, so nothing is left blocked by an object that no longer has an owner.
The tables themselves go last, once the reload has dropped the rules that used
them, because pf keeps a table after a reload and would otherwise go on listing the
three, empty, until the next reboot. Everything is matched by description marker,
so a renamed alias is still found. Safe to run repeatedly.

**It refuses, and changes nothing, while anything else in the configuration refers
to one of the plugin's aliases**: a rule of yours with `NoInternet` as its source,
an alias of yours that lists one, a NAT rule, another plugin's setting, even a
description that names one as a whole word. Deleting the alias would leave that
rule pointing at nothing. It prints where each reference is; change those, then
run it again. The plugin's own rules, aliases, cron job and settings do not count.

It also waits, for up to 30 seconds, for a running sync to finish, and reads the
configuration again once it has the sync lock, so that a sync in progress cannot
save its own copy over the removal. It puts a marker,
`/var/db/parentalcontrol/uninstalling`, down before it starts and keeps it once
everything is removed, so that a sync that was already waiting changes nothing
("uninstall in progress - nothing changed") instead of putting the objects back. A
refusal or a failure takes the marker away again, because not everything was
removed. The script deletes the data directory, marker included, last, and a new
install removes the marker. Ctrl-C, hangup and TERM are handled the same way: an
interrupted run takes its marker away, so syncs carry on and restore anything it had
already flushed, and running the script again finishes the job. SIGKILL, or a PHP
without pcntl, can leave the marker behind, which pauses every sync until
`uninstall.sh` is run again or the plugin is reinstalled.

This genuinely removes the plugin — the menu entry disappears and the widget
drops off the dashboard. Note that `configctl parentalcontrol install` will not
work afterwards, because the action file is one of the things it deletes; use the
codeload bootstrap under Install to come back.

Device settings stay in `config.xml`, so reinstalling restores them. Add
`--purge` to remove those as well, the messaging settings included.

The config-side cleanup is also available on its own as
`configctl parentalcontrol uninstall` — useful to disable enforcement while
leaving the plugin installed. It removes the same firewall objects and the cron job
but not the files, the data directory or the marker, so the next sync puts the
objects back. Two things to know about it:

- When it refuses, configctl shows only "Execute error". Run `uninstall.sh` (or
  `uninstall.php`) directly to read why.
- A sync that was already waiting for the lock can put the objects back within the
  same minute. If the aim is to stop enforcement, disable the plugin first.

The shell script is deliberately *not* a configd action, because it deletes the
action file and restarts configd, and a script configd launched would be killed
partway through that.

If a library file is missing, `sync.php` and `uninstall.php` print one line naming
it and exit 1 instead of failing inside PHP. That line reaches you only when you
run them directly: configctl and the GUI show their own error.

## Known limits

Blocking messaging is by server address, which sets its limits:

- IPv4 only, like the rest of the plugin (see Requirements).
- WhatsApp may sometimes connect through addresses built into the app that its
  names never resolve to.
- A blocked app still works on mobile data, through a VPN app on the device, or on
  another network.
- Apps move servers. A new server address is picked up on the first sync after it
  appears in DNS. Addresses an app uses without ever announcing them in DNS are not
  caught.
- Blocking is by server address, not by app, so a selected app's web version is
  blocked too.
- While a device is denied messaging it gets no notifications from any app, no
  iMessage and no incoming FaceTime calls. Ordinary phone calls and SMS over the
  mobile network still work. Notifications held back during the block may all
  arrive when it lifts.
- Phones that use a manufacturer's own notification service instead of Google's, as
  some phones sold in China do, are not covered.
- Apple's ranges and Microsoft's list are only as current as the installed plugin
  version.
- The list of apps is one for every device: there is no per-device choice.

## What has been verified, and what has not

**Release 0.0.2** (the whole-internet block) was verified by observation against a
live firewall, not by reading the code: settings save and device CRUD; alias and
rule creation, inspected field by field; schedule evaluation at several times of
day; adding to and removing from the pf table; cron driving it unattended
(observed acting 31 seconds after a config change, with no manual trigger); and
renaming the alias in both directions, confirming the aliases rename in place, the
rule follows, the old pf table is flushed, no duplicate pair appears and
enforcement never lapses.

**Release 0.1.0 (messaging), so far.** Four test files, which need no OPNsense and
no network, only PHP 8.1 or later (with its dom, ctype, filter and SimpleXML
extensions) and Python 3:

    php tests/ScheduleTest.php; php tests/MessagingTest.php; php tests/MessagingDataTest.php; python3 -I tests/BuildMessagingDataTest.py

Each prints a line per check and exits 1 when one fails. All pass, re-run on
2026-10-18:

| File | Checks | What it covers |
|---|---|---|
| `tests/ScheduleTest.php` | 19 | the schedule decision |
| `tests/MessagingTest.php` | 151 | the messaging decision, the IPv4 helpers, the builders of the server list and its cache, the choice of connections to drop, alias conflicts, and the scan of the configuration that uninstall relies on |
| `tests/MessagingDataTest.php` | 18 | `messaging.json`: no protected name or private range in it, the notification ranges and names pinned, and its agreement with the settings model |
| `tests/BuildMessagingDataTest.py` | 3 | which part of Microsoft's list the data generator turns into ranges |

**Mutation testing.** While the code was written, its checks were run against
deliberately broken copies of what they cover: a comparison flipped, a guard
dropped, a constant changed. A check that no broken copy could fail was changed
until one could. The schedule tests had been checked that way against the pre-fix
logic, which they fail on 4 cases. The counts reported for the rest: 24, 50, 65,
128 and 8 broken copies of the library, at the five stages `MessagingTest.php` was
built in; 31 of the data file and 23 of the model for `MessagingDataTest.php`,
whose alias-name check was also shown failing against the old pattern; and 5 of the
generator for `BuildMessagingDataTest.py`. Every one was caught except seven in
`MessagingTest.php`: five behave the same as the original on every input that can
occur, and two fold the case of alias names, which the tests leave open on purpose.

**Static analysis (0.1.0)**, on 2026-10-18. PHPStan 2.3.1 at its strictest level
(9), over the plugin's scripts, library files, controllers and model, with OPNsense
core 26.7.7's sources and the plugin's own library files and model on its scan
path, reports 79 findings, none of them a defect:

- 66 are what it cannot follow in the framework: model fields reached through
  `__get` and iterators typed as `mixed` (35), `Config::getInstance()` typed as
  plain `object` (6), Phalcon's view and core's own docblocks naming classes that
  do not exist (10), core's `getModel()` documented as possibly null though it
  never is (2), `config.inc` on PHP's include path (4), and the API actions' JSON
  arrays having no declared value types (9).
- 13 are new in this release, all in `lib/serverlist.php`: the messaging data file
  and the state listing that configd returns are decoded from JSON, which PHPStan
  types as `mixed`, so it cannot check the calls that take their parts. The data
  file's shape is pinned by `tests/MessagingDataTest.php`, a state row missing a
  key is ignored by `selectStatesToDrop()`, and a damaged data file ends in the
  refresh's own error handling, which Status shows, never in the internet block.

Semgrep 1.181.0, with its default, PHP, JavaScript, Python, secrets and
security-audit rule sets, run over everything git tracks but the tests (which it
skips), reports three findings:

- `exec-use` on the `pfctl -k` call in `sync.php` that drops a blocked device's
  connections. Its argument goes through `escapeshellarg()` and can only be an
  address that passed the model's IPv4/CIDR/MAC mask or the ARP and lease
  parsing, and a network is skipped.
- `unlink-use` in `uninstall.php`, on the shutdown function that removes the
  marker. Its argument is the constant path of the marker, never input.
- `dynamic-urllib-use-detected` in `tools/build-messaging-data.py`. The generator
  takes its URLs from the committed `tools/messaging-sources.json`, all of them
  `https`, and from fixed RIPEstat addresses. It is a maintainer's tool, never
  installed, and reads nothing from outside the repository.

Its bash parser also cannot read `install.sh` in full (`sh -n` and `dash -n` are
clean on every script). Every function, API action, configd action and installed
file has a caller.

**Exercised on a development machine, not on OPNsense.** `sync.php` and
`uninstall.php` were run against stand-ins for OPNsense's model, configuration and
configd classes through dozens of scenarios (a first use, a name conflict, a dead
resolver, an unwritable cache, a refusal, a running sync, signals, a missing
library file). The Status tab's script was rendered in headless Chrome against
fixture data, and the installer's hand-over and the upgrade from 0.0.2 were run with
a fake download against the real installers in scratch directories. Those
harnesses are scratch tools and not in the repository. They show that the logic does
what it should, not that OPNsense agrees.

**Still to come:** the run of `tests/vm/uninstall-test.sh` on a disposable OPNsense
26.7 virtual machine (it saves `config.xml`, installs, exercises all three
settings, uninstalls with and without `--purge`, and compares `config.xml` with the
saved copy), and the device checks in `docs/testing/messaging-block.md`: each app,
the notification cut, the schedule and a reboot. Until they have been done, nothing
in 0.1.0 has been run on OPNsense itself. Their results are added here when they
exist.

**Not implemented:** IPv6 blocking — see Requirements below.

## Requirements

OPNsense 26.7 or later.

**IPv4 only, and this matters.** The block rule is `inet`, so on a dual-stack
firewall a blocked device keeps full internet over IPv6 — and will prefer it —
while every indicator here says "blocked". The Status tab detects active IPv6
and warns in red, but detection is not prevention: if you run IPv6, this plugin
does not do what it claims. Blocking IPv6 properly needs a second rule plus
`ndp`-based MAC resolution, which is not implemented.

Messaging blocking is IPv4 only as well: both messaging rules are `inet` and only
IPv4 answers are learned, so an app or a notification service reached over IPv6 is
not blocked.

## Release notes

For whoever cuts a release. None of this is installed on the firewall.

**Regenerating `messaging.json`.** The shipped list of messaging and notification
servers is generated and then committed:

    python3 -I tools/build-messaging-data.py --out src/opnsense/scripts/OPNsense/ParentalControl/messaging.json

The generator takes the hand-maintained part from `tools/messaging-sources.json`
(each app's host names, the protected names, Apple's ranges, Google's names and
ports, and the pinned Microsoft file) and downloads the rest: Telegram's ranges,
the shared CDNs' ranges (Cloudflare, Fastly, Amazon CloudFront, and Akamai's
announced prefixes from RIPEstat) and Microsoft's Windows Notification Service
list. A failed download, an entry that is not an address range, a Microsoft file
without its client section, or a list that comes out empty stops it with exit
status 1 before anything is written. `--wns FILE` reads a local copy of Microsoft's
file instead of downloading it. Read the diff, and run the tests, before
committing the result.

    python3 -I tools/build-messaging-data.py --check

resolves every app, notification and protected host name through this machine's
resolver and prints each address with its network and owner and what the guard
would do with it (kept, protected, or shared CDN), then the owner of every shipped
range. Use it when a host name is added or an app moves servers. The output shows
the DNS of the network it ran on: read it, never commit it.

**Before each release, check for a newer Microsoft WNS file.** The generator pins
one dated file, `WNSPublicIPs_<date>.xml`, in `tools/messaging-sources.json`
(`notifications.microsoft_ranges_url`), because Microsoft's Download Center item
44238, "Windows Notification Service (WNS) VIP and IP Ranges", has no stable
address: every new version has a new one. Open the item's page (the generator's
docstring gives the address of a copy rendered on the server, which shows the file
name) and, if the file there is newer than the pinned one, update the URL and
regenerate. While Microsoft still serves the old file nothing fails when it goes
stale: the notification cut just covers a list that ages. If Microsoft takes the
old file down, the build fails loudly instead.

**The release itself:** set `PLUGIN_VERSION` in the `Makefile`, add the entry to
the changelog in `pkg-descr`, update the test counts and the static-analysis
paragraph above, and tag. The `<version>` in `ParentalControl.xml` is the schema
version of the saved settings, not the release; it changes only when the model
does.
