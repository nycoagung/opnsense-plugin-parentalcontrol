# Messaging block: design

Date: 2026-10-18 · Status: draft for approval · Plugin: os-parentalcontrol (current release 0.0.2)

## Why

Parents want to switch off chat apps on a child's device without cutting the rest of
the internet: homework time, bedtime, or "no WhatsApp this week". Today the plugin can
only cut a device's whole internet. This feature adds a second, narrower switch for
messaging apps. Gaming follows later as its own feature; this design should not make
that harder (see "Shape for later categories").

## What was asked for

- A **Messaging** setting on every device, in the device's edit dialog, with three
  choices: permanently blocked, never blocked, or following the device's schedule.
- It must **work without Pi-hole** or any other DNS filter.
- **Removing the plugin must not affect, modify or break OPNsense or any other
  installed plugin.**
- Everything double-checked and tested.

Agreed during design (proposed, then confirmed by the maintainer):

- "Follow the schedule" means messaging is blocked outside the device's allowed hours
  (its Days and Internet allowed from/until fields), even while the device's internet
  is forced on by Override → Force allow.
- Override (Force allow / Force block) keeps controlling only the whole-internet
  switch. It never changes the messaging decision.
- Which apps count as messaging is one list in the general settings, the same for
  every device.
- Calling and work tools used for school and family (Teams, Skype, Slack, FaceTime,
  iMessage) are not on the app list.
- Blocked means completely blocked, including calls and message previews. Previews
  can only be stopped by cutting the device's notification services, so while a
  device is denied messaging it receives no notifications from any app. That also
  stops iMessage and incoming FaceTime calls on that device for the duration, because
  both depend on Apple's notification service. The maintainer chose this knowingly
  (2026-10-18) over keeping notifications and turning previews off inside each app.

## Goals

1. Each device gets a messaging setting with three values: `off` (default), `on`,
   `schedule`.
2. A device denied messaging cannot reach the selected apps' servers over IPv4. The
   rest of its internet is unaffected. Blocked means completely blocked: the apps'
   voice and video calls stop too (maintainer's decision, 2026-10-18), which follows
   naturally because calls are set up through the same servers as chats.
3. While a device is denied messaging, it also cannot reach Apple's, Google's or
   Microsoft's notification services, so no notification of any kind, and therefore no
   message preview, reaches it. Notifications resume when the block lifts.
4. A change takes effect on Apply or within the existing one-minute cycle, and drops
   the device's open connections to those servers, so a chat that is already open
   does not carry on.
5. No dependency on Pi-hole, on any DNS filter, on Suricata, on Zenarmor, or on
   anything else that is not part of OPNsense core.
6. Uninstalling leaves the firewall exactly as it was before the plugin was installed,
   apart from settings the user chose to keep.

## Non-goals

- Gaming, streaming or social-network blocking (later features).
- Per-device choice of apps: the app list is global.
- Blocking Instagram or Facebook direct messages. Their chat runs on the same servers
  as the rest of each app, so the network can only block the whole app.
- Phones on mobile data, devices on other networks, VPN apps on the device.
- IPv6. The plugin is IPv4-only today and says so (README, Requirements).
- Changes to the dashboard widget.
- Filtering notifications per app. The network cannot: every app's notifications
  share one connection per device.
- Any integration with Pi-hole or another DNS filter.

## Anti-goals (a failure even if it technically works)

- Breaking anything on a blocked device beyond the agreed effects (the selected apps,
  their calls, and all notifications including iMessage and incoming FaceTime): web
  browsing, video, school tools, iCloud, app stores, or Facebook and Instagram, which
  are not on the list.
- Any effect at all on a device that is not denied messaging, including its
  notifications.
- Notifications that stay cut after the block lifts.
- Changing any OPNsense setting or object the plugin does not own, or leaving
  anything behind after uninstall.
- A status page that says "blocked" while the app still works, without saying why.

## Constraints

- OPNsense 26.7 or later, PHP like the existing scripts, the existing configd actions,
  and the existing per-minute cron job (no new cron job).
- The plugin recognises its own objects by description marker, never by name
  (existing rule, see `sync.php`).
- pf table names are at most 31 characters.
- Public repository: documentation values only in code, tests, docs and examples.
- The README's uninstall promise extends to everything this feature adds.

## Where the block happens, and why

Blocking by name (DNS) would be more precise, but the plugin cannot rely on it. On many
home networks the device's lookups never pass through the firewall, for example when a
DNS filter sits on the same LAN segment as the devices. A firewall-side DNS policy would
then only ever see that filter as the client. What the firewall always sees is where a
device's internet traffic goes. So the plugin blocks by **destination address**. It
keeps its own list of the selected apps' server addresses, and a device denied
messaging cannot reach them. This mirrors the whole-internet switch: a table of
devices and a block rule, here with a second table for the destinations.

The consequence is that only apps whose servers are their own, not shared with
unrelated sites, can be blocked without collateral damage. A feasibility probe on
2026-10-18 sorted the candidates (see "App list").

## The per-device setting

New field on every device: **Messaging**, with values `off` "Not blocked" (the default
for existing and new devices), `on` "Blocked", and `schedule` "Blocked outside
allowed hours".

The decision for a device, evaluated on every sync:

| Condition (first match wins) | Messaging blocked? | Reason text (indicative) |
|---|---|---|
| plugin disabled | no | plugin disabled |
| device disabled | no | device disabled |
| messaging = off | no | messaging allowed |
| messaging = on | yes | messaging always blocked |
| messaging = schedule | what the existing `scheduleDecision()` returns for the device's Days and allowed window, unchanged, including midnight-crossing windows and failing closed on invalid times | messaging outside allowed hours / within allowed hours |

Mode and Override do not appear in this table. When a device's internet is blocked,
its messaging is blocked anyway as a side effect. Status still reports the messaging
decision exactly as computed.

The Days and allowed from/until fields are already always visible in the device
dialog, so no form logic changes. Their help text should say that they also drive
Messaging when it follows the schedule.

Device addressing reuses the existing resolution (IPv4 address, CIDR, or MAC through
ARP and DHCP leases), with its existing limits.

## App list (general setting)

General settings gain **Messaging apps to block**, a multi-select of the supported
apps. The default selection is every app marked "offered" below.

The probe resolved each app's main server names through a normal home DNS path and
checked which network owns each address:

| App | Where its servers are | Verdict |
|---|---|---|
| WhatsApp | Meta's network, but answered from addresses of its own, separate from Facebook and Instagram | offered |
| Telegram | its own network; publishes its address ranges | offered |
| Snapchat | its own cloud load balancers on Google Cloud and AWS | offered, without its CDN host names |
| Signal | AWS Global Accelerator and Google Cloud for chat and calls | offered; its CDN and storage names sit on shared Google, Cloudflare and CloudFront front ends and are left out |
| Viber | AWS EC2 for its website; the probe did not reach its chat servers | offered only once its chat servers are found and pass the same checks; otherwise dropped |
| LINE | its own network | offered |
| Messenger | a Meta address shared with Facebook's Graph API, which the Facebook app and "Log in with Facebook" in other apps rely on | not offered |
| Discord | Cloudflare's shared front end | not offered |
| Kik | Fastly's shared front end | not offered |
| WeChat | Tencent's shared infrastructure | not offered |

The README lists the apps that are not offered, with the reason, so nobody wonders
why they are missing.

## The messaging server list

The list is data shipped with the plugin, as a JSON file next to the scripts. For each
app it holds an id, a label, the host names to resolve, and optionally the URL of
officially published address ranges (Telegram:
`https://core.telegram.org/resources/cidr.txt`). The file also holds a list of
**protected names** whose addresses must never be blocked, for example
`www.facebook.com`, `facebook.com`, `graph.facebook.com`, `edge-mqtt.facebook.com`,
`www.instagram.com` and `i.instagram.com`.

On every sync, for the selected apps:

1. Resolve each host name's A records through the firewall's own resolver.
2. Drop any address that a protected name resolves to in the same run, and any address
   inside a known shared-CDN range (Cloudflare, Fastly, Akamai, Amazon CloudFront).
   The shared-CDN ranges ship with the plugin, so this guard needs nothing from the
   network at run time. Google's shared front ends are covered by protected names
   instead of a range, because Google's network also holds addresses that must be
   blocked, such as its notification servers.
3. Merge the result into the learned set with a last-seen time. An address not seen
   for 7 days expires. This rides out DNS rotation, since the apps' names rotate
   through pools of addresses with short lifetimes, and it survives reboots.
4. Fetch published ranges at most once a day. On failure, keep using the last good
   copy. A copy shipped with the plugin is the first fallback.
5. Load the result into the servers table, writing only the differences.

The learned set is cache, not configuration. It lives in a plugin-owned file outside
config.xml and is removed by uninstall.

A failed lookup never empties the list: a stale list is better than none. Failures
are reported in status.

## Notifications

A phone receives every app's notifications over one shared connection to its
platform's notification service, so the network cannot remove one app's previews and
keep the rest. For a device denied messaging, the plugin therefore blocks the
notification services themselves. Their addresses join the same destination list as
the messaging apps, and Google's notification ports get the second rule below, so
they are blocked for exactly the same devices at exactly the same times. This happens
whenever a device is denied messaging, whichever apps are selected, even none:

| Service | Used by | What is blocked | Source |
|---|---|---|---|
| Apple Push Notification service | iPhone, iPad, Mac | all traffic to Apple's published APNs ranges, IPv4 `17.249.0.0/16`, `17.252.0.0/16`, `17.57.144.0/22`, `17.188.128.0/18`, `17.188.20.0/23`. This covers port 5223 and the port 443 fallback. | Apple support article 102266, "If your Apple devices aren't getting Apple push notifications" |
| Firebase Cloud Messaging | Android phones and tablets | TCP ports 5228 to 5230 to any destination, which Google reserves for this service, plus all traffic to the addresses of `mtalk.google.com`, `mtalk4.google.com` and `alt1-mtalk.google.com` to `alt8-mtalk.google.com`, which covers the port 443 fallback | Firebase documentation, "Configure your Network for FCM" |
| Windows Notification Service | Windows PCs | all traffic to the address ranges in Microsoft's published WNS list | Microsoft Download Center item 44238, "Windows Notification Service (WNS) VIP and IP Ranges" |

The probe on 2026-10-18 checked these against services that must keep working. Apple's
notification servers answered from inside the published ranges, while iCloud's
servers sit outside them. Google's notification servers had addresses of their own,
separate from Search, YouTube, Google's APIs and Classroom. Windows notification
servers did not share addresses with Microsoft sign-in, Outlook or Teams. The
protected-names guard still applies to all of these, and the protected names gain the
corresponding Apple, Google and Microsoft names.

The Apple ranges and the Microsoft list ship with the plugin and change only with
plugin updates. Microsoft's list has no stable download address, so it is not fetched
at run time. Apple's ranges have been stable for years. The Google host names are
resolved on every sync like the messaging apps' names.

## Firewall objects

Every new object is plugin-owned and found by description marker, like the existing
ones:

| Object | Type | Content | Description marker |
|---|---|---|---|
| `NoMessaging` | external alias (pf table) | addresses of devices currently denied messaging | `Parental Control: devices currently denied messaging` |
| `Messaging_Servers` | external alias (pf table) | current messaging app and notification service addresses and ranges | `Parental Control: messaging servers` |
| a floating rule | block, quick, direction in, IPv4, no interface (every interface) | source `NoMessaging`, destination `Messaging_Servers` | `Parental Control: block messaging` |
| a second floating rule | block, quick, direction in, IPv4, TCP, no interface | source `NoMessaging`, destination any, ports 5228 to 5230 | `Parental Control: block notification ports` |

The names are fixed, not user-configurable. If an alias with one of these names
already exists and is not the plugin's, the plugin does not take it over: messaging
blocking stays off with a clear error in status, and the whole-internet switch keeps
working as before.

Creation follows the existing pattern: validate, save, and one filter reload only
when something changed. Table contents are maintained with the existing configd
table actions, so toggling a device never reloads the ruleset. Both rules use the
action `block`, the same as the whole-internet rule.

Dropping connections follows the existing "Drop existing connections" setting. When a
device starts being denied messaging, drop only its states whose destination is in the
servers table, plus its states to TCP ports 5228 to 5230. Notification services hold
one long-lived connection per device, so without this a phone would keep receiving
notifications until that connection happened to close. When new server addresses
appear while devices are denied, drop those devices' states to the new addresses.
Never touch any other state. This is best effort and never fails the sync.

## Status and UI

- Device dialog: the Messaging field's help text says plainly that blocking messaging
  also stops every notification on the device, including iMessage and incoming
  FaceTime calls, until the block lifts.
- Devices grid: a new "Messaging" column.
- Status tab: a "Messaging" column per device showing blocked or allowed with the
  reason, plus a summary line with the number of server addresses, the time of the
  last refresh, and any lookup or download errors.
- `parentalcontrol status` JSON gains per-device `messaging`, `messaging_blocked` and
  `messaging_reason`, and a top-level `messaging` object with the table name, entry
  count, last refresh, selected apps and errors. Existing keys keep their meaning,
  because the widget reads them.
- Dashboard widget: unchanged.

## Uninstall: leaving OPNsense untouched

The feature only ever adds: two aliases, two rules, the contents of two tables, one
data file, and fields inside the plugin's own config subtree. It never edits core
settings (DNS, DHCP, NAT, intrusion detection) or other plugins' objects.

Uninstall removes all of it in a safe order: flush both tables, delete the rules,
delete the aliases, then remove files. `configctl parentalcontrol uninstall` handles
the config side, extended to the new markers. `uninstall.sh` removes the data file.
`--purge` also removes the new settings, since they live in the existing subtree that
purge already removes.

Uninstall must not silently leave a user's own rule pointing at an alias it is about
to delete. If any rule that is not the plugin's refers to one of the plugin's aliases,
uninstall stops before changing anything and names that rule. This covers the
existing aliases too.

## Known limits (to be stated in the README)

- IPv4 only, like the rest of the plugin.
- WhatsApp may sometimes connect through addresses built into the app that its names
  never resolve to.
- A blocked app still works on mobile data, through a VPN app, or on another network.
- Apps move servers. A new server address is picked up on the first sync after it
  appears in DNS. Addresses an app uses without ever announcing them in DNS are not
  caught.
- Blocking is by server address, not by app, so a selected app's web version is
  blocked too.
- While a device is denied messaging it gets no notifications from any app, no
  iMessage and no incoming FaceTime calls. Ordinary phone calls and SMS over the
  mobile network still work. Notifications held back during the block may all arrive
  when it lifts.
- Phones that use a manufacturer's own notification service instead of Google's, as
  some phones sold in China do, are not covered.
- Apple's ranges and Microsoft's list are only as current as the installed plugin
  version.

## Verification: what "tested" means

1. Table-driven tests that run without OPNsense, in the style of
   `tests/ScheduleTest.php`: the messaging decision table above, and the server-list
   merge (deduplication, expiry, protected-name subtraction, the shared-CDN guard, bad
   input, empty answers keeping the stale list), notification destinations being
   present whatever apps are selected, and the choice of which states to drop. Every
   new test is shown to fail against a deliberately broken version.
2. Static analysis as for 0.0.2 (PHPStan level 9, Semgrep), with no new findings
   beyond the known framework ones.
3. On a disposable OPNsense 26.7 virtual machine that the maintainer provides and
   runs: a script supplied by the builder saves config.xml, installs, enables, adds a
   device, exercises all three settings, uninstalls, and compares config.xml with the
   saved copy. Only revision metadata may differ. It repeats this with `--purge`. The
   comparison covers the whole of config.xml, so objects belonging to any other
   plugin on the machine are checked too; the machine should have internet access and
   ideally the same plugins as the real firewall. The maintainer runs the script and
   returns its output; the builder checks it.
4. On the real firewall, with a test device in hand, ideally one iPhone or iPad and
   one Android phone. Blocked: each selected app stops sending, receiving and calling
   within a minute, and open chats drop; no notification of any kind arrives,
   including iMessage and FaceTime; web browsing, YouTube, iCloud, the app store,
   Teams, Facebook and Instagram keep working. A second device that is not blocked
   keeps receiving notifications throughout. Not blocked: everything is restored and
   notifications resume. Follow schedule: the block flips at the window's edge. The
   status page agrees throughout, and the block survives a reboot.
5. A config-side uninstall-and-resync rehearsal on the real firewall
   (`configctl parentalcontrol uninstall && configctl parentalcontrol sync`), comparing
   the inventory of plugin objects before and after.

The builder runs 1 and 2, writes the script for 3, and writes 4 and 5 as
step-by-step checks. Steps 3 and 4 need the maintainer's hands: the virtual machine
and the device. Installing on the real firewall needs a shell, the console or SSH.

## Shape for later categories

Gaming will add a similar per-device setting, matching gameplay ports instead of
server addresses. Keep the per-device decision and the "table, rule and marker"
plumbing general enough that a second category can reuse them, but do not build any
gaming parts now.

## Left to the builder

- File layout: whether server-list maintenance lives in `sync.php` or a new script,
  the JSON schema of the data file, and the data file's path.
- How the shared-CDN ranges are sourced and shipped. They must work offline at run
  time.
- The exact host names per app. Start from the probe, and verify each against the
  guard and on a device.
- Reason strings and UI wording beyond what is fixed here.
- How the model change is versioned, provided existing configs load cleanly and
  existing devices come up as `off` without user action.
- The release version number. Releasing needs the maintainer's explicit go-ahead.
- Confirming, early, the core behaviour this design leans on: that an external
  alias's table contents survive a filter reload (the existing whole-internet switch
  already relies on it), and that host-name lookups from the sync script use the
  firewall's own resolver.

## Calls made during design

- Blocking by destination address at the firewall, which follows from "without
  Pi-hole".
- Fixed alias names `NoMessaging` and `Messaging_Servers`. A clash with an existing
  alias of the same name leaves messaging blocking off, reported in status, rather than
  stopping the whole-internet switch.
- Resolution on every sync, a 7-day expiry for learned addresses, and a daily fetch
  of published ranges.
- The default app list is the apps marked "offered": WhatsApp, Telegram, Snapchat,
  Signal and LINE, plus Viber if its chat servers verify. Messenger, Discord, Kik and
  WeChat are not offered.
- Cutting notifications is part of every messaging block and has no switch of its
  own, as the maintainer asked; a switch can be added later if wanted.
- The second rule blocks Google's notification ports to any destination rather than
  to a list of Google addresses, because Google discourages address lists for this
  service and reserves those ports for it.
- The status JSON is extended; the widget is untouched.
- The uninstall test compares the whole of config.xml rather than a list of known
  objects, so it covers whatever other plugins the test machine has.
