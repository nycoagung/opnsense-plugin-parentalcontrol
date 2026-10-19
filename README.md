# os-parentalcontrol

Turn a device's internet access on or off, on a schedule or on demand. A second,
narrower switch blocks just a device's messaging apps and leaves the rest of its
internet alone.

Adds **Firewall → Parental Control** and a dashboard widget with per-device
switches. Blocked devices lose *internet only* — printers, media players, NAS
and local dashboards keep working.

## How it enforces

The internet switch is two aliases and one rule: an externally-managed alias
holding the addresses currently denied internet, a companion alias defining
private address space, and one floating rule blocking the first to anything
outside the second. Toggling a device is a pf table update, so it takes effect
immediately with no ruleset reload.

Per-device rules were rejected deliberately: they do not scale, they apply
slowly, and arbitrary per-device schedules would need one rule per distinct
schedule. A fixed few objects stay comprehensible at any number of devices: one
table and one rule for the internet, two tables and two rules for messaging.

Both aliases and the rule are created automatically on first apply and are
idempotent. The plugin recognises its own objects by a description marker rather
than by name, so your existing rules are never touched and nothing is duplicated.

**Renaming the alias is safe.** Because the marker is name-independent, changing
`Alias name` renames the existing aliases in place and repoints the rule at them,
rather than creating a second pair and leaving the first enforcing invisibly. The
old pf table is removed on the way through, so a device that happened to be
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

Toggling a device is again a pf table update, with no ruleset reload. The servers
table can hold thousands of entries, so each change to it is loaded with one pfctl
call. The four
objects are made the first time a device uses messaging (see Blocking messaging),
so a firewall that never does gains nothing, and they are found by description
marker like the rest.

Unlike the internet alias, their names are fixed, and a rename by hand in Firewall →
Aliases is not followed as it is for that one: the plugin fills only the table of
the fixed name, and only while its own alias has that name. After a rename it adds
to neither table and only takes entries out of the old one, so the rules that use
the renamed alias block nothing until it is renamed back. The Status tab reports
it, saying "messaging alias renamed" with the name the alias has and the name it
should have. If an alias with one of those names already exists and the plugin did
not make it, the plugin does not take it over: messaging blocking stays off, the
Status tab says why, and the internet switch keeps working. For the same reason the
internet alias itself cannot be named `NoMessaging` or `Messaging_Servers`.

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
it within a minute. That also clears the learned addresses of every app: the apps
that stay selected are looked up again at once, but the other addresses their names
had rotated through are lost, and are learned again as the names rotate.
Deselecting every app is allowed, and leaves only the notification cut described
next.

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

**That also stops iMessage and incoming FaceTime calls on that device**: both use
Apple's notification service, which carries every app's notifications, so they
stop with all of them for as long as the block lasts. Ordinary phone calls and SMS
over the mobile network still work. There is no switch for this: it is part of
every messaging block. Notifications held back may all arrive when it lifts.

Apart from that and the selected apps, nothing else on the device is touched: web
browsing, video, iCloud, the app stores and school and work tools such as Teams keep
working. Two of the apps take more with them; see The apps.

### The apps

Offered: **WhatsApp, Telegram, Snapchat, Signal, LINE, Viber, KakaoTalk, and
Facebook & Instagram**. An app is offered only where its servers are its own, so
that blocking them takes nothing unrelated with it. That is why Snapchat's and
Signal's content-delivery names, which sit on shared front ends, are left out of
their lists. Two entries take more with them, on a blocked device only:

- **Facebook & Instagram** blocks the whole Facebook, Instagram and Messenger apps,
  and their websites. Messenger and Instagram's direct messages run on the same
  servers as the rest of each app, so the network can only block them whole. It
  also takes Threads, Meta AI, meta.com and its Accounts Center, "Log in with
  Facebook" on other sites and apps, and signing in to a Meta Quest from that
  device.
- **KakaoTalk** hands its chat and call servers to the app as bare addresses,
  never through DNS, so it can only be blocked by Kakao's own address space. That
  also takes other Kakao services: Daum, Tistory, Melon, Kakao Map, Kakao
  Developers and "Log in with Kakao".
- **LINE** is blocked by LINE's own network (AS38631) as well as by name: its
  gateways sit in several countries, with a backup, and the app moves between
  them. That also takes LINE's other services on that network with it.

Not offered, and why:

| App | Why not |
|---|---|
| Discord | it sits behind Cloudflare's shared front end |
| Kik | it sits behind Fastly's shared front end |
| WeChat | its servers are Tencent's shared infrastructure |

Teams, Skype and Slack are not on the list.

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
  services' names first so that a slow resolver cannot starve them, then the
  apps' names in turns, one name of each app at a time, so that an app with a
  long list cannot starve one with a short list. Lookups are limited in time,
  and the names not reached are reported.
- **Meta's servers at every site.** Meta's DNS hands a device a WhatsApp,
  Facebook or Instagram server at whichever of its sites it picks at that moment,
  one nearby one minute and one on another continent the next, so a lookup once a
  minute can miss the one a device was given. The plugin therefore ships the addresses
  of these apps' servers at every Meta site, about 870 for WhatsApp and 3,800 for
  Facebook & Instagram, built at release time from Meta's per-site server names
  (such as `whatsapp-chatd-edge-shv-01-ams2.facebook.com`) and kept only where
  they lie in Meta's own network (AS32934). They are blocked whenever the app is
  selected. The two lists share no address, so blocking one app leaves the other
  working.
- **Remembering.** An address is kept for **7 days** after it was last seen,
  because the apps' names rotate through pools of short-lived addresses. The list
  survives a reboot: it lives in `/var/db/parentalcontrol`, outside `config.xml`.
- **Public addresses only.** Only public IPv4 answers are learned, so a resolver
  that sinkholes a name with `0.0.0.0` or a local address cannot get a device's
  own network blocked.
- **Published ranges.** Telegram's are downloaded from core.telegram.org once a
  day, with an hour's wait after a failed download. The last good copy, then the
  copy shipped with the plugin, stand in for a download that fails. Every range,
  downloaded, kept from an earlier download or shipped, is checked before it can be
  blocked: one wider than a /8 is left out, and so is one that reaches into
  private, local, carrier-grade NAT or multicast address space, since blocking that
  would cut a device off its own network or the firewall; Status lists what was
  left out. Telegram's apps also have an address built in from a block registered
  to Telegram and left out of its list, 95.161.64.0/20 (announced as two /21s);
  that block ships with the plugin and is blocked with whichever list is used.
- **Shipped ranges.** Viber's, KakaoTalk's and LINE's networks ship with the
  plugin and are not downloaded: the networks registered to Viber in ARIN's
  registry, the address blocks Kakao announces from its own networks (AS7625,
  AS18160, AS38099 and AS152199), and those LINE announces (AS38631). They change
  only with a new release.
- **Never emptied.** A failed lookup or download does not empty the list, and the
  errors show in Status.
- **Protected names.** A list of names that must never be blocked (iCloud,
  Google, Microsoft sign-in and the like) is resolved on every run, and an
  address one of them resolves to is never learned. Neither is any address inside
  a shared-CDN range (Cloudflare, Fastly, Akamai, Amazon CloudFront); those
  ranges ship with the plugin, so this guard works offline. Addresses already
  learned are checked again every run. If a protected name cannot be resolved and
  nothing recent is remembered for it, learning pauses: the plugin cannot tell
  whether an address it finds is shared with that name, so it learns nothing new
  until the name resolves again, and Status says "learning paused: no answer for
  …". The guard also looks inside ranges: an app's range (Telegram's downloaded
  ones, Viber's, KakaoTalk's and LINE's shipped ones) that holds a protected name's
  address is left out ("range … holds protected address …; not blocked"), while a
  notification range that holds one stays blocked, because the notification cut
  wins, and Status names it too.
- **Protected names with an owner.** Some protected names belong to an app on the
  list: Facebook's and Instagram's main names (`facebook.com`,
  `www.facebook.com`, `graph.facebook.com`, `edge-mqtt.facebook.com`,
  `www.instagram.com`, `i.instagram.com`) to Facebook & Instagram, and
  `g.whatsapp.net`, its fallback `g-fallback.whatsapp.net`, `web.whatsapp.com` and
  `mmg.whatsapp.net` to WhatsApp. Such a name is protected only while its app is
  not selected. So blocking WhatsApp alone
  cannot take Facebook or Instagram with it, blocking Facebook & Instagram alone
  cannot take WhatsApp with it, and selecting both blocks both. A protected name
  without an owner is protected whichever apps are selected.

A change takes effect on Apply or within the one-minute cycle. The Settings tab
has no Save button: Apply saves its settings, when they changed, then applies them.

### Status

The Status tab gains a **Messaging** column per device (blocked or allowed, with
the reason) and a summary line: "Messaging: not in use" while no enabled device
uses messaging; "Messaging: N server entries · not refreshed yet" until the first
refresh has run (Apply runs a sync, which refreshes at once); then "· refreshed
HH:MM". Behind it come any lookup, download or protected-range errors and, in red,
why messaging cannot be enforced at all: a reserved name taken by someone else's
alias, a missing object, a messaging alias renamed by hand, or an unreadable data
file. `configctl parentalcontrol status` carries the same under a `messaging` key,
and per device `messaging`, `messaging_blocked` and `messaging_reason`. The
existing keys are unchanged, which is what the dashboard widget reads.

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
install runs **a second time**. That line is not shown in the GUI:
`configctl parentalcontrol sync` reports only "Error (1)", the Apply button only
"failed", and the Status tab stays blank. Running
`/usr/local/opnsense/scripts/OPNsense/ParentalControl/sync.php` directly prints it.
The first run also copies the new installer, which does the rest. Run
`configctl parentalcontrol install` twice in a row, or use the codeload bootstrap
under Install, which runs the new release's installer from the start. From 0.1.0
on this cannot recur, because an installed installer hands over to the downloaded
release's own.

## Upgrading from 0.1.0

One install run is enough: 0.1.0's installer hands over to the downloaded one,
which also migrates the saved settings, because the settings model changed.

The migration adds Facebook & Instagram, KakaoTalk and Viber to a saved app
selection, so a firewall that blocked all five apps blocks all eight. **From the
first sync after the upgrade, a device denied messaging also loses Facebook,
Instagram, Messenger, Viber and KakaoTalk, with the services they take with them
(see The apps), for as long as it is denied.** Deselect any of them under Settings
and press Apply if that is not wanted. A selection that was saved empty stays
empty, and nothing else in the settings changes.

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
  after it (see Upgrading from 0.0.2). Run the install by hand, twice in a row.

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
to one of the plugin's aliases**: a rule of yours with the internet alias
(`NoInternet` by default) as its source, an alias of yours that lists one, a NAT
rule, another plugin's setting, even a description that names one as a whole word.
Deleting the alias would leave that rule pointing at nothing. It prints where each
reference is; change those, then run it again. The plugin's own rules, aliases, cron
job and settings do not count.

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
without pcntl, can leave the marker behind, which pauses every sync. That is easy to
miss, because Apply still reports OK (a paused sync exits 0) while nothing changes.
To notice it, look for the file `/var/db/parentalcontrol/uninstalling`, or run
`sync.php` directly and read its "uninstall in progress - nothing changed". To clear
it, run `uninstall.sh` again to finish the removal, or reinstall to keep the plugin.

This genuinely removes the plugin — the menu entry disappears and the widget
drops off the dashboard. Note that `configctl parentalcontrol install` will not
work afterwards, because the action file is one of the things it deletes; use the
codeload bootstrap under Install to come back.

Device settings stay in `config.xml`, so reinstalling restores them. Add
`--purge` to remove those as well, the messaging settings included.

The config-side cleanup is also available on its own as
`configctl parentalcontrol uninstall` — useful to disable enforcement while
leaving the plugin installed. It removes the same firewall objects and the cron job
but not the files, the data directory or the marker. With the cron job gone nothing
syncs by itself, and while the plugin is enabled the Status tab shows the schedule
cron as not installed. The next sync somebody starts (Apply,
`configctl parentalcontrol sync`, or a flip of the dashboard widget's switch) puts
the internet objects back, the cron job too while the plugin is enabled, and the
messaging objects once a device uses messaging. Two things to know about it:

- When it refuses, configctl shows only "Execute error". Run `uninstall.sh` (or
  `uninstall.php`) directly to read why.
- A sync that was already waiting for the lock can put the objects back within the
  same minute. If the aim is to stop enforcement, disable the plugin first.

The shell script is deliberately *not* a configd action, because it deletes the
action file and restarts configd, and a script configd launched would be killed
partway through that.

If a library file they need is missing, `sync.php` and `uninstall.php` print one
line naming it and exit 1 instead of failing inside PHP. That line reaches you only
when you run them directly: `configctl parentalcontrol sync` reports only
"Error (1)", the Apply button only "failed", the Status tab stays blank, and
`configctl parentalcontrol uninstall` reports only "Execute error".

## Known limits

Blocking messaging is by server address, which sets its limits:

- IPv4 only, like the rest of the plugin (see Requirements).
- WhatsApp may sometimes connect through addresses built into the app that its
  names never resolve to. Its fallback chat name, which it moves to within a minute
  or two once its main chat server is blocked, is on its list.
- A DNS filter that blocks an app's names for a device, such as a Pi-hole group with
  blocklists, makes some apps fall back to addresses built into them. The plugin
  never sees those addresses in DNS, so it does not block them; Meta's apps do this.
- A blocked app still works on mobile data, through a VPN app on the device, or on
  another network.
- Apps move servers. A new server address is picked up on the first sync after it
  appears in DNS. Addresses an app uses without ever announcing them in DNS are not
  caught.
- Blocking is by server address, not by app, so a selected app's web version is
  blocked too.
- Facebook & Instagram, KakaoTalk and LINE take other services with them on a
  blocked device: Threads, Meta AI, "Log in with Facebook" and the rest, other
  Kakao services such as Daum and Kakao Map, and LINE's other services (see The
  apps).
- Meta also serves photos and videos from caches inside internet providers'
  networks, which are not blocked. The apps still stop, because their own servers
  are blocked, but a photo linked from elsewhere may still load.
- A proxy set up in an app gets around the block: Signal's and Telegram's proxies,
  WhatsApp's proxy setting and Viber's. So does Signal's censorship circumvention,
  which the app offers once it cannot connect: it goes through Google's and
  Fastly's front ends, which cannot be blocked without blocking them.
- When Telegram cannot reach its servers it fetches a backup address over
  DNS-over-HTTPS or from Google's Firebase, and that address can lie outside its
  ranges.
- Viber calls may still connect: its call relays could not all be identified.
- Some apps' names point at cloud load balancers (Snapchat's, Viber's, and LINE's
  gateway in the United States, on Amazon Web Services or Google Cloud). An
  address learned there is kept for 7 days, so if the provider hands it to another
  customer meanwhile, that customer's service is blocked on blocked devices until
  the address is forgotten.
- When Messenger signs in on a device for the first time, its one-time setup of
  encrypted chats may use `v.whatsapp.net`, which is on WhatsApp's list. With
  WhatsApp selected and Facebook & Instagram not, that setup may fail on a blocked
  device. This comes from Messenger's public client code and has not been seen on
  a device.
- While a device is denied messaging it gets no notifications from any app, no
  iMessage and no incoming FaceTime calls. Ordinary phone calls and SMS over the
  mobile network still work. Notifications held back during the block may all
  arrive when it lifts.
- The notification cut covers the platforms' push services: Apple's, Google's and
  Microsoft's. An app that keeps its own push connection, such as a browser's web
  push or some desktop clients, is not covered.
- Phones that use a manufacturer's own notification service instead of Google's, as
  some phones sold in China do, are not covered.
- Apple's ranges, Microsoft's list, and Viber's, KakaoTalk's and LINE's networks
  are only as current as the installed plugin version. So are Meta's per-site
  servers: a site Meta opens after a release is blocked only once its addresses
  come up in the apps' names.
- The list of apps is one for every device: there is no per-device choice.
- While a device uses messaging and the firewall's own resolver is failing, each
  per-minute sync spends up to about 30 to 40 seconds on lookups while it holds its
  lock. An Apply or a widget change made then waits, and can fail with "another
  sync is still running" (Apply shows only "failed"); the next per-minute sync
  applies it. Scheduled changes still go first in each run.

## What has been verified, and what has not

**Release 0.0.2** (the whole-internet block) was verified by observation against a
live firewall, not by reading the code: settings save and device CRUD; alias and
rule creation, inspected field by field; schedule evaluation at several times of
day; adding to and removing from the pf table; cron driving it unattended
(observed acting 31 seconds after a config change, with no manual trigger); and
renaming the alias in both directions, confirming the aliases rename in place, the
rule follows, the old pf table is flushed, no duplicate pair appears and
enforcement never lapses.

**Tests.** Six test files, which need no OPNsense and no network, only PHP 8.1 or
later (with its dom, ctype, filter and SimpleXML extensions) and Python 3:

    php tests/ScheduleTest.php; php tests/MessagingTest.php; php tests/MessagingDataTest.php; php tests/MigrationTest.php; php tests/FirewallTest.php; python3 -I tests/BuildMessagingDataTest.py

Each prints a line per check and exits 1 when one fails. All pass, re-run on
2026-10-19:

| File | Checks | What it covers |
|---|---|---|
| `tests/ScheduleTest.php` | 19 | the schedule decision |
| `tests/MessagingTest.php` | 167 | the messaging decision, the IPv4 helpers, the order names are looked up in, which protected names count for the selected apps, the builders of the server list and its cache, the check of ranges for protected addresses, the choice of connections to drop, alias conflicts, and the scan of the configuration that uninstall relies on |
| `tests/MessagingDataTest.php` | 45 | `messaging.json`: no private range or shared-CDN range in it, no address in two apps' ranges, the servers of WhatsApp and Facebook & Instagram at Meta's sites, no blocked range reaching into special-purpose space, a host name for every app, a protected name listed by an app only when that app owns it, the owners, the apps' fallback, gateway and message-connection names, Telegram's extra range, Viber's, KakaoTalk's and LINE's ranges (no wider than /8, clear of the shared CDNs and the notification ranges), the Facebook & Instagram list, the notification ranges and names and the protected Facebook and Instagram names pinned, and its agreement with the settings model, which selects every app by default |
| `tests/MigrationTest.php` | 6 | the settings migration to 1.2.0: a saved selection gains the new apps, an empty one stays empty |
| `tests/FirewallTest.php` | 5 | loading a pf table: one pfctl call for the additions and one for the deletions, configd for each address when pfctl fails |
| `tests/BuildMessagingDataTest.py` | 30 | which part of Microsoft's list the data generator turns into ranges, how it builds the apps' ranges from RIPEstat and ARIN and from Meta's per-site server names and checks them, that no address is in two apps' ranges, and its check of the protected names' owners |

**Mutation testing.** While the code was written, its checks were run against
deliberately broken copies of what they cover: a comparison flipped, a guard
dropped, a constant changed. The schedule tests had been checked that way against
the pre-fix logic, which they fail on 4 cases. The counts reported for the rest:
24, 50, 65, 128 and 8 broken copies of the library, at the five stages
`MessagingTest.php` was built in; 31 of the data file and 23 of the model for
`MessagingDataTest.php`, whose alias-name check was also shown failing against the
old pattern; and 5 of the generator for `BuildMessagingDataTest.py`. Later checks
added 11 broken copies of the library and 8 of the data file, the model and the
rule's ports, every one caught. In the first three library stages each of
the 89 checks was shown failing under at least one broken copy. Every broken copy
was caught except seven in `MessagingTest.php`: five behave the same as the
original on every input that can occur, and two fold the case of alias names,
which the tests leave open on purpose. For 0.2.0, 33 more broken copies were all
caught: 12 of the library, 8 of the generator, 10 of the data file, 2 of the
migration and 1 of the table loading.

**Static analysis (0.2.0)**, on 2026-10-19. PHPStan 2.3.1 at its strictest level
(9), over the plugin's scripts, library files, `controllers/` and model, its
migration included, with OPNsense core 26.7.7's sources and the plugin's own
library files and model on its scan path, reports 86 findings, none of them a
defect:

- 67 are what it cannot follow in the framework: model fields reached through
  `__get` and iterators typed as `mixed` (35), `Config::getInstance()` typed as
  plain `object` (6), Phalcon's view and core's own docblocks naming classes that
  do not exist (10), core's `getModel()` documented as possibly null though it
  never is (2), `config.inc` on PHP's include path (4), the API actions' JSON
  arrays having no declared value types (9), and the migration's `run()`
  parameter, which core's migration base class leaves untyped and an override may
  not narrow (1).
- 18 are in `lib/serverlist.php`: the messaging data file and the state listing
  that configd returns are decoded from JSON, which PHPStan types as `mixed`, so it
  cannot check the calls that take their parts. The data file's shape is pinned by
  `tests/MessagingDataTest.php`, a state row missing a key is ignored by
  `selectStatesToDrop()`, and a damaged data file ends in the refresh's own error
  handling, which Status shows, never in the internet block.
- 1 is in `lib/messaging.php`: `messagingHosts()` passes each app's host list
  through `array_values()`, which the list's declared type makes look redundant.
  The list comes from the same JSON, which is not checked entry by entry, and the
  apps' turns read it by position, so the call stays.

0.1.0 had 84; the new ones are the migration's and `array_values()`'s.

Semgrep 1.181.0, with its default, PHP, JavaScript, Python, secrets and
security-audit rule sets, run over everything git tracks but the tests (which it
skips), reports the same three findings as for 0.1.0:

- `exec-use` on the `pfctl -k` call in `sync.php` that drops a blocked device's
  connections. Its argument goes through `escapeshellarg()` and can only be an
  address that passed the model's IPv4/CIDR/MAC mask or the ARP and lease
  parsing, and a network is skipped.
- `unlink-use` in `uninstall.php`, on the shutdown function that removes the
  marker. Its argument is the constant path of the marker, never input.
- `dynamic-urllib-use-detected` in `tools/build-messaging-data.py`. Every URL the
  generator opens has a fixed scheme and host: it comes from the committed
  `tools/messaging-sources.json`, all of them `https`, or is built on one of two
  fixed `https` templates. RIPEstat's,
  `https://stat.ripe.net/data/<call>/data.json?resource=<resource>`, takes one of
  the three calls the code names (`network-info`, `as-overview` and
  `announced-prefixes`) and an address or an AS number; ARIN's,
  `https://whois.arin.net/rest/nets;name=<name>`, takes a network name from the
  sources file. It is a maintainer's tool and is never installed.

Semgrep's bash parser also cannot read `install.sh` in full (`sh -n` and `dash -n`
are clean on every script). Every function, API action, configd action and
installed file has a caller.

**Exercised on a development machine, not on OPNsense.** `sync.php` and
`uninstall.php` were run against stand-ins for OPNsense's model, configuration and
configd classes through dozens of scenarios (a first use, a name conflict, a dead
resolver, an unwritable cache, a refusal, a running sync, signals, a missing
library file). The Status tab's script was rendered in headless Chrome against
fixture data, and the installer's hand-over and the upgrade from 0.0.2 were run with
a fake download against the real installers in scratch directories. For 0.2.0, the
settings migration ran through OPNsense core 26.7.7's own `run_migrations.php` and
model classes over scratch `config.xml` files, with only a pass-through of
Phalcon's validation standing in (core's own validators ran): 0.1.0's five apps
became all eight, an empty selection stayed empty, a partial one gained the missing
apps once, sorted, 0.0.2's settings got all eight, and settings already at 1.2.0
were left alone. The Settings tab's Apply ran in headless Chrome with core 26.7.7's
own jQuery, Bootstrap, token field and form scripts and a stubbed API: it saves
changed settings before applying, saves nothing when they did not change or have
not loaded yet, and on a validation error applies nothing and brings up the tab.
Those harnesses are scratch tools and not in the repository. They show that the
logic does what it should, not that OPNsense agrees.

**On OPNsense.** 0.1.0 was installed on a live OPNsense 26.7.7 firewall from its
branch, and sections 4 to 7 of the device checklist passed with one Android phone:
its messaging apps failed, no notification arrived, and the rest of its internet
and the other devices kept working. That run found that OPNsense turns PHP's URL
downloads off (`allow_url_fopen`), so Telegram's ranges could not be downloaded;
downloads now use PHP's curl extension, which OPNsense core depends on.

0.2.0 was installed on the same firewall from its branch, as an upgrade from 0.1.0:
the settings migration turned the saved five apps into all eight. With the same
phone:

- the Settings tab has no Save button, a change brings up the reminder to apply,
  and an invalid alias name stops Apply and brings up the tab with the field
  marked;
- with all eight apps selected, the phone's messaging apps failed, calls included,
  no notification arrived, and web browsing, YouTube and Google Play kept working.
  The servers table held about 4,900 entries, each change loaded with one pfctl
  call, and every sync answered OK within seconds;
- with only WhatsApp selected, WhatsApp failed, messages and calls, also five
  minutes later;
- with only Facebook & Instagram selected, Facebook, Instagram and Messenger
  failed and WhatsApp worked.

That run found the two gaps fixed before this release: WhatsApp moving to its
fallback chat name, and Meta's DNS sending the app to a server at a site on another
continent, which led to blocking every Meta site.

**Still to come:** the run of `tests/vm/uninstall-test.sh` on a disposable OPNsense
26.7 virtual machine (it saves `config.xml`, installs, exercises all three
settings, checks that every app is selected by default, that the migration adds
the new apps to a saved selection and that the per-site servers reach the servers
table, uninstalls with and without `--purge`, and compares `config.xml` with the
saved copy); and the rest of the device checklist: back to Not blocked, the
schedule, an empty app selection, Messenger's first sign-in with only WhatsApp
selected, a reboot, the status page, the uninstall rehearsal, and the apps the test
phone does not have. Their results are added here when they exist.

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
(each app's host names and where its ranges come from, the protected names and
their owners, Apple's ranges, Google's names and ports, and the pinned Microsoft
file) and downloads the rest: Telegram's ranges; the networks of each app with
`ranges_sources`, merged, which are Viber's networks from ARIN's registry by
network name (`{"arin_name": "AWS-VIBER"}`) and Kakao's announced prefixes from
RIPEstat by AS number (`{"ripestat_asn": 7625}`); the shared CDNs' ranges
(Cloudflare, Fastly, Amazon CloudFront, and Akamai's announced prefixes from
RIPEstat); and Microsoft's Windows Notification Service list. It stops with exit
status 1 before anything is written on a failed download, an entry that is not an
address range, a Microsoft file without its client section, a list that comes out
empty, an app range that is wider than a /8, overlaps a shared CDN or a
notification range, or reaches into private or other special-purpose address space,
or an owner given for a name that is not protected or naming no app. `--wns FILE`
reads a local copy of Microsoft's file instead of downloading it. Read the diff,
and run the tests, before committing the result.

    python3 -I tools/build-messaging-data.py --check

resolves every app, notification and protected host name through this machine's
resolver and prints each address with its network and owner and what the guard
would do with it (kept, protected, or shared CDN), then which protected names each
app owns and the owner of every shipped range. An app's own host names are judged
without the protected names it owns, as the plugin judges them while that app is
selected. Use it when a host name is added or an app moves servers. The output
shows the DNS of the network it ran on: read it, never commit it.

**Before each release, check Viber's, Kakao's and Telegram's sources.** Their
networks are built from the network names, AS numbers and extra ranges in
`tools/messaging-sources.json`, which do not update themselves. Check in RIPEstat
that Telegram's networks (AS62041, AS59930, AS62014, AS44907 and AS211157)
announce nothing outside its published list and its `extra_ranges`. Check in
ARIN's registry that Viber's networks are still registered as `AWS-VIBER-MEDIA`
and `AWS-VIBER`, with no new one under another name, and in RIPEstat that Kakao
still announces from AS7625, AS18160, AS38099 and AS152199, with no new AS of its
own, and that LINE still announces from AS38631. Kakao Games, Kakao Pay, KakaoBank
and Kakao Pay Insurance are separate companies with their own networks and stay
out. Then regenerate and read the diff: a range that grows a lot needs a look
before it ships.

**Before each release, refresh Meta's site list.** `meta_sites` in
`tools/messaging-sources.json` lists the server-and-site suffixes (`01-ams2`) of
Meta's per-site server names, and each app's `meta_site_hosts` the name of each
kind of server with `{site}` in place of the suffix. The list was taken on
2026-10-19 from ProjectDiscovery's Chaos dataset for facebook.com: every
`-shv-NN-SITE` suffix of the kinds of server the templates name. Take it again
from there, or from the reverse names of the servers the apps reach, so that new
sites are blocked from the start. A build resolves about 20,000 names and takes a
minute or two; stale suffixes simply do not resolve. Build through an unfiltered,
unthrottled resolver: a DNS filter, or one that limits queries per client, would
answer some names wrongly or not at all. The build tries a lookup that fails for the
moment once more, prints how many names of each template answered, and stops on an
answer no public server has (0.0.0.0, a private address) and on a per-site list
more than a tenth shorter than the committed one. When sites really have closed,
`--accept-drop` writes the shorter list.

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
does. When saved settings must change with it, add a migration named after the new
version, as `Migrations/M1_2_0.php` is for 1.2.0, and add it to the installer's
file list: the installer runs core's migrations whenever the model changes.

**Change the configd action file only in a release that needs it**, because the
previous release's installer restarts configd when that file changes.
