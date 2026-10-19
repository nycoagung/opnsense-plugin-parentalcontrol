# Gaming block: design

Approved by the maintainer, 2026-10-19 · builds on the messaging block (`2026-10-18-messaging-block-design.md`,
0.1.0) and more messaging apps (`2026-10-19-more-messaging-apps-design.md`, 0.2.0, not
merged yet) · target release 0.3.0.

## Why

Parents want live online games off on a child's device, always or outside set hours,
without cutting the rest of the internet. ASUS routers offer this as a "Games" tick per
device. ASUS recognises games with a closed deep-packet-inspection engine. OPNsense core
has nothing like it: Suricata has no game rules, nDPI runs only inside ntopng (which
monitors and cannot block), and Zenarmor's per-device policies are paid. So this plugin
blocks the kind of traffic real-time games send.

## What was asked for

- A **Gaming** setting on every device that works like Messaging: Not blocked, Blocked,
  Blocked outside allowed hours (maintainer, 2026-10-19).
- One switch for all games, with no per-game list: "just a global Block or not on each
  device settings" (2026-10-19).
- Live online gameplay, not game websites or launchers (2026-10-18).
- Block "by kind of traffic all real-time games use", and do it precisely: only games
  break (2026-10-19). The summary of this design was confirmed before it was written.
- "Also block games like or from: mihoyo, yuanshen, hoyolab, infoldgames" (2026-10-19):
  miHoYo/HoYoverse's games (Genshin Impact, called Yuanshen in China, Honkai: Star Rail,
  Zenless Zone Zero, Honkai Impact 3rd), its HoYoLAB community app, and Infold Games'
  games (Infinity Nikki, Love and Deepspace, Shining Nikki), by name, whatever traffic
  they use.
- Still binding from the messaging requirements: it works without Pi-hole; removing the
  plugin must not affect, modify or break OPNsense or any other installed plugin;
  everything is double-checked and tested.
- A gaming phone on the maintainer's network is the reference device (2026-10-19).

## Goals

1. Every device gets a gaming setting: `off` (the default), `on`, `schedule`.
2. While a device is denied gaming, over IPv4 it cannot:
   - send UDP to the internet, except to the safe-list of non-game services (rule 1);
   - open TCP connections to the internet on the ports games use for live play (rule 2);
   - reach the servers of the named game publishers, and of live games that play over
     ordinary web traffic (rule 3).
3. Everything else on that device keeps working: web, video and music, app stores and
   game downloads, notifications, chat and calls in the messaging apps, FaceTime, Zoom,
   Teams, Meet, Webex, Wi-Fi Calling, time and name lookups, Discord voice, the local
   network, and connections to the firewall itself (such as a VPN back home).
4. A change takes effect on Apply or within the one-minute cycle, and drops the device's
   open game connections, so a match in progress ends.
5. No dependency on Pi-hole, a DNS filter, Suricata, Zenarmor or anything else outside
   OPNsense core. No new cron job or configd action.
6. Uninstalling leaves the firewall exactly as it was before the plugin was installed,
   apart from settings the user chose to keep.

## Non-goals

- A list of games to choose from, globally or per device.
- Game websites, stores, launchers, downloads, game videos and streams. The maintainer
  named one, HoYoLAB, but it runs on shared CDNs and cannot be blocked without a DNS
  filter (see rule 3).
- Browser games. They run over ordinary web connections, so only a DNS filter can tell
  them apart.
- Consoles (Switch, PlayStation, Xbox). Their documented ports cover nearly all of UDP;
  the whole-internet switch is the tool for them.
- Apple Game Center multiplayer that runs over FaceTime's ports. Screen Time controls it.
- Phones on mobile data, other networks, and VPN apps that tunnel over TCP 443 or IKEv2.
- IPv6. The plugin is IPv4-only and says so.
- Changes to the dashboard widget or the Settings tab.

## Anti-goals (a failure even if it technically works)

- On a device denied gaming: failed calls in the messaging apps, FaceTime, Zoom, Teams or
  Meet, missing notifications, Wi-Fi Calling not working, or broken web or video.
- Any effect on a device that is not denied gaming.
- A pass rule. The gaming rules only block, so they can never let through traffic that
  another rule, the plugin's or the user's, blocks.
- Changing an object the plugin does not own, or leaving anything behind after uninstall.

## Constraints

As for messaging: OPNsense 26.7 or later; PHP like the existing scripts; the existing
configd actions and per-minute cron job, with the configd action file byte-identical to
0.0.2; objects found by description marker, never by name; pf table names of at most 31
characters; documentation values only in code, tests, docs and examples (public
repository); the README's uninstall promise covers everything added.

Order of work (maintainer, 2026-10-19: "review 0.2.0 first"): 0.2.0, which is not merged
yet, gets its final review before any gaming code is written, because the gaming block
reuses its server list, bulk table loading and connection drops. The review's fixes land
on the 0.2.0 branch (`more-messaging-apps`), and the gaming branch is rebased onto the
result.

## Where the block happens, and why

pf matches a packet's protocol, destination port and destination address, nothing
deeper. Real-time games send their gameplay as UDP to ports of their own (see
"Evidence"). Ordinary apps use TCP, or QUIC on port 443. The few non-game services that
use other UDP ports document them: calls, Wi-Fi Calling and time. So for a device denied
gaming, the plugin blocks UDP to the internet by default and lets through only the
documented non-game ports. That is a list of what must keep working, not a list of games,
so games nobody listed are caught too. A few games play over TCP or ordinary web traffic;
for those the plugin adds their TCP ports and, where it has to, their servers' addresses.

## The per-device setting

New field on every device: **Gaming**, with values `off` "Not blocked" (the default for
existing and new devices), `on` "Blocked", and `schedule` "Blocked outside allowed
hours".

The decision is the messaging decision with "gaming" in its reasons: plugin disabled →
no; device disabled → no; `off` → no; `on` → yes; `schedule` → what the existing
`scheduleDecision()` returns for the device's days and allowed window, including
midnight-crossing windows and failing closed on invalid times; an unrecognised value →
yes. Mode and Override do not take part, as for messaging.

Help text in the device dialog: "Blocks live online games. Calls, Wi-Fi Calling, web,
video and app downloads keep working. Browser games, consoles and mobile data are not
covered." The Days and allowed-hours help text also mentions Gaming.

## Rule 1: UDP, except the safe-list

Block, quick, direction in, IPv4, UDP, from the devices denied gaming, to any
destination that is not local (see "What counts as local"), on any destination port
that is not on the safe-list.

The exception is written as a port alias holding every port from 1 to 65535 *except*
the safe-list, not as pass rules. A pass rule would let a gaming-blocked device through
other blocks, such as the internet switch, the messaging block or the user's own rules.

The safe-list (destination UDP ports a device denied gaming may still use):

| Ports | Kept working | Source |
|---|---|---|
| 53 | name lookups | standard |
| 123 | time | standard |
| 443 | QUIC and HTTP/3: web, video, iCloud Private Relay | RFC 9308; Apple, "Prepare your network for iCloud Private Relay" |
| 500, 4500 | Wi-Fi Calling (IKEv2 and IPsec to the carrier) | Apple 103229; carrier documentation |
| 3478–3497 | call relays (STUN and TURN): FaceTime, Teams 3478–3481, Zoom 3478–3479, Meet 3478, Signal's one-to-one relay; WhatsApp and Messenger calls were seen on 3478 | Apple 102036; Microsoft 365 URLs and IP ranges; Zoom KB0060548; Google Meet network guide; Signal support; reference phone |
| 8801–8810 | Zoom | Zoom KB0060548 |
| 10000 | Signal group calls | Signal support |
| 16384–16387, 16393–16402 | FaceTime | Apple 102036 |
| 19302–19309 | Google Meet | Google Meet network guide |

Every safe-listed port is a small hole: a game server that happens to listen on it gets
through. So a port is only safe-listed when its service has no better option. Left off on
purpose:
- 4398 and 16403–16472: used only by Game Center.
- 853, DNS over QUIC: clients fall back to DNS over TLS or plain DNS, so nothing breaks,
  and a DNS filter stays in force.
- 50000–65535, the range third parties report for Discord voice. Opening it would let
  through Roblox (which plays on 49152–65535) and most peer-to-peer game traffic. Discord
  is handled by address instead (see "What counts as local").
- Viber's 4244, 5242, 5243 and 7985. They sit inside game ranges (Riot 5000–5500, Call of
  Duty Mobile 7500–8000, Agones 7000–8000). Viber is handled by address instead, like the
  other messaging apps with networks of their own (see "What counts as local").
- Webex's 5004 and 9000 (inside Riot's and Rocket League's ranges). Webex falls back to
  TCP by design (Webex WBX000028782), so it keeps working.
- 5349, TURN over DTLS: little used, and inside Riot's range.

## Rule 2: the TCP ports of games

Block, quick, direction in, IPv4, TCP, from the devices denied gaming, to any destination
that is not local, on these destination ports:

| Ports | Game or platform | Source |
|---|---|---|
| 9339 | Supercell games: Clash of Clans, Clash Royale, Hay Day, the Brawl Stars lobby | FortiGuard application signature; reference phone |
| 25565 | Minecraft Java servers (their default port) | Minecraft protocol documentation |
| 19132–19133 | Minecraft Bedrock server joins (signalling, from version 26.60; servers may use other ports) | GeyserMC, 2026-10-08 |
| 4530–4533, 9090–9093, 19090–19093 | games built on the Photon engine (Stumble Guys, Rec Room, Gorilla Tag, VRChat and many Unity games), when they fall back from UDP to TCP or WebSocket | Photon documentation, "TCP and UDP port numbers" |
| 37000–37100 | games using Unity Relay over WebSocket | Unity documentation |
| 65010, 65050 | Call of Duty Mobile lobby and chat | Activision support |
| 6112–6119, 20500, 20510, 28960 | Call of Duty on PC; Battle.net also uses 6112–6114 | Activision support |
| 1119–1120, 3724 | Battle.net: Hearthstone, World of Warcraft, Overwatch 2 | Blizzard (archived copy of its port article) |
| 2099, 8393–8400 | Riot Client: Valorant, League of Legends, TFT | Riot support |
| 6695–6709 | Warframe | Digital Extremes support |
| 50010–50014 | World of Tanks | Wargaming support |
| 7800–7802, 7850–7854 | War Thunder | Gaijin support |
| 7500–7509, 30000–30009 | Destiny 2 | Bungie support |
| 2567, 7348–7351, 7770, 7777, 27777 | default ports of common game-server software (Colyseus, Nakama, FishNet, Unreal, Unity and Mirror), for self-hosted and indie games | each project's documentation |

Gameplay of all these games is UDP and already caught by rule 1; these ports add their
login, lobby and chat, and the games that fall back to TCP. Known clashes, accepted:
8400 is also a business backup product's port, and 6697 is IRC over TLS.

Never added, because other things depend on them: 5222–5223 (chat apps' XMPP and Apple's
notification service), 5228–5230 (Google's notification service), 5060 and 5062 (SIP
calls), 3478–3480 (call relays), 3074 (Xbox network), 4000, 8080, 8088, 8090, 8443,
6881, 27014–27050 (the Steam client itself), and wide ranges such as 12000–65000.

Left for a packet capture before adding, since only secondary sources give them: Free
Fire 39003, 39698 and 39779; PUBG Mobile 10012 and 17500; Mobile Legends 30000–30999;
Arena of Valor 10001–10094; Ubisoft Connect and the EA app's lists.

## Rule 3: game servers by address

Block, quick, direction in, IPv4, any protocol, from the devices denied gaming, to the
game-servers table. This covers two things rules 1 and 2 cannot: the publishers the
maintainer named, whose games are blocked whatever traffic they use, and live games that
play over ordinary web traffic.

The table holds the host names below, global servers only. Each was checked on
2026-10-19 to resolve to addresses of the game's own (plain A records on cloud instances
or load balancers, with no other names on them), never to a shared CDN:

| Game | Host names | Addresses today |
|---|---|---|
| Pokémon GO | `pgorelease.nianticlabs.com` | a Google Cloud load balancer of Niantic's |
| Honkai Impact 3rd | `outer-dp-overseas01`, `outer-dp-usa01`, `outer-dp-jp01`, `outer-dp-kr01`, `outer-dp-asia01` and `westglobal01` (all `.honkaiimpact3.com`): the per-region game-server lookup | Alibaba Cloud instances |
| Honkai Impact 3rd | `prelogin.global.honkaiimpact3.com`, `account-west.honkaiimpact3.com`: login | miHoYo's own AWS load balancers; the addresses rotate, which resolving on every sync follows |
| Infinity Nikki | `x6us-gate`, `x6en-gate`, `x6tw-gate`, `x6asia-gate`, `x6us-auth`, `x6en-auth`, `x6tw-auth`, `x6asia-auth` (all `.infoldgames.com`) | Alibaba Cloud instances |
| Love and Deepspace | `x3asia-gatesvr`, `x3en-gatesvr`, `x3us-gatesvr`, `x3tw-gatesvr` and the same four with `-loginsvr` (all `.infoldgames.com`) | one Alibaba Cloud address in Singapore that carries only these names |
| in-game voice chat (Vivox, used by many games) | shipped ranges `85.236.96.0/21` and `85.236.104.0/23` (Vivox's published addresses, 2026-08-06) | Vivox's own |

Not on the list, because they cannot be blocked precisely:
- Genshin Impact, Honkai: Star Rail and Zenless Zone Zero: every login, dispatch and web
  API host sits on a shared front end (Cloudflare, AWS CloudFront, Alibaba's CDN and
  Anti-DDoS service). Their gameplay is caught by rule 1 instead, on its own UDP ports:
  the games still open and sign in, but cannot enter the world.
- HoYoLAB: all of it is web traffic on shared CDNs. Only a DNS filter can block it.
- Shining Nikki: no game host was found.
- The publishers' download and update servers, and the China builds (`bh3.com`,
  `papegames.com`, `yuanshen.com` China dispatch).

Neither publisher has a network of its own. AS203923, named "HOYOVERSE-AS", is a
look-alike that is unrelated to miHoYo and must never be shipped.

The table is kept by the same machinery as the messaging server list: names resolved on
every sync, the guard (protected names, shared-CDN ranges, public addresses only),
learned addresses expiring after 7 days, ranges shipped with the plugin, and a data file.

The shared-CDN guard has to grow for these hosts. The same Alibaba CDN edge addresses
serve HoYoLAB, HoYoverse and Infold names today, and some CloudFront hosts answer with
edge addresses directly, with no CloudFront alias in between. So:
- the shipped shared-CDN ranges gain Alibaba Cloud's CDN and Anti-DDoS edges and Tencent
  EdgeOne, wherever they are published or can be listed;
- the data-building tool rejects a host at release time if its answer comes through a
  CDN alias (names under `kunlun*`, `alikunlun`, `cdngslb`, `aliyunddos*`, `dnsv1`,
  `eo.dnse3`, `whecloud`, `cloudfront`, `akamai*`, `cloudflare` and the like), or lands in
  a shared range, or carries other sites' names in passive DNS.

A game whose service sits on a shared front end (Cloudflare, Azure Front Door, Akamai,
CloudFront) cannot be listed here. Fortnite's and Minecraft's account services are like
that. Their live play is caught by rule 1, or is a known limit.

## What counts as local

Rules 1 and 2 never block traffic to:
- private addresses (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16), link-local
  169.254.0.0/16, multicast 224.0.0.0/4 and broadcast 255.255.255.255, so DHCP, mDNS,
  SSDP and LAN games are untouched;
- the firewall's own addresses on every interface, so a VPN back home, or a home service
  reached through the home's public address, keeps working.

Rule 1 also never blocks UDP to the networks of calling services that own them, so their
calls work on whatever port they pick, without opening that port to games:
- Discord's registered address block, 66.22.192.0/18 (RIPE netname
  `US-DISCORD1-20000919`; i3D.net, Google and Amazon announce parts of it). Some Discord
  voice servers run elsewhere on Google Cloud, so Discord voice can still fail now and
  then while gaming is blocked.
- The messaging apps' own networks, taken from the data the messaging block already
  ships, whichever apps are selected there: Telegram's published ranges, LINE's and
  Kakao's announced prefixes, Viber's registered networks and Meta's per-site server
  addresses (WhatsApp, Facebook, Instagram and Messenger). None of these networks hosts
  other companies' games.

On a device denied both messaging and gaming, the messaging rule still blocks those apps.
This exemption only stops the gaming rule from blocking them as well.

## Evidence

**Research, 2026-10-19.** Vendor documentation first, then measurements, labelled.

| Game | Live play uses | Caught by |
|---|---|---|
| Roblox | UDP 49152–65535 | rule 1 |
| Fortnite | UDP (Epic lists 12000–65000) | rule 1 |
| Minecraft Bedrock servers | UDP (19132 by default; NetherNet since 26.50) | rule 1 |
| Minecraft Java | TCP 25565 by default, but any port is possible | rule 2, default port only |
| Clash of Clans, Clash Royale, Hay Day | TCP 9339 | rule 2 |
| Brawl Stars | lobby on TCP 9339, battles on UDP | rules 2 and 1 |
| Among Us | UDP 22023 | rule 1 |
| Pokémon GO | HTTPS on TCP 443 | rule 3 |
| PUBG Mobile, Call of Duty Mobile, Free Fire | UDP 10010–13972 and 20000–20002; 7500–7650; 10011–10015 (secondary sources) | rule 1 |
| Mobile Legends | battles on UDP around 5001–5600 | rule 1 |
| Genshin Impact; Honkai: Star Rail; Zenless Zone Zero | UDP 22101–22102; 23301–23302; 20501 (community packet-capture tools; no official list) | rule 1 |
| Honkai Impact 3rd | not verified (older versions: TCP 16100) | rule 3, by its game-server lookup hosts |
| Infinity Nikki; Love and Deepspace | not found | rule 3, by their gate and login hosts |
| Valorant; League of Legends, TFT | UDP 7000–8000 and 8180–8181; 5000–5500 (Riot support) | rule 1 |
| CS2, Dota 2 | UDP 27015–27140 through Steam Datagram Relay (Valve's relay configuration) | rule 1 |
| Apex Legends; Rocket League; Overwatch 2 | UDP 37000–40000 and 1024–1124; 7000–9000; 12000–64000 | rule 1 |
| Photon-engine games (Stumble Guys and many Unity games) | UDP 5055–5058 or 27000–27002; falls back to TCP 4530–4533, WebSocket 9090–9093, or secure WebSocket on 19090–19093 or 443 | rules 1 and 2; the fallback on 443 is not caught |
| Minecraft Bedrock friend worlds | WebRTC through Microsoft's call relays on UDP 3478–3481 | not caught |
| Game Center multiplayer | FaceTime's ports, plus 4398 and 16403–16472 | partly |
| Browser games | WebSocket over TCP 443 | not caught |

**Holes that remain** (safe-listed UDP ports that games also use): Game Center's matches
on FaceTime's ports; Minecraft Bedrock relays on 3478–3481; voice services and Steam's
peer-to-peer voice on 3478; Fortnite's, Riot's voice and Vivox's wide ranges, which
include a few safe-listed ports; QUIC on 443 for some voice SDKs. Each is in "Known
limits".

**Reference phone, 14 days of the firewall's flow records and its live connection list.**
- Clash of Clans played on TCP 9339 to 47 different Amazon addresses, so a port rule beats
  an address list for it.
- WhatsApp and Messenger calls used UDP 3478 to Meta's relays (safe-listed).
- Wi-Fi Calling used UDP 500 and 4500 (safe-listed).
- QUIC on 443 was most of the traffic.
- A WireGuard VPN to the home's own public address ran all day, which is why the
  firewall's own addresses count as local.
- Speed-test apps used UDP 8080 and the traceroute ports from 33434. Their UDP checks will
  fail while gaming is blocked; that is accepted.
- No real-time UDP game was played on it in that time.

## Firewall objects

Every object is plugin-owned and found by description marker:

| Object | Type | Content |
|---|---|---|
| `NoGaming` | external alias (pf table) | devices currently denied gaming |
| `Gaming_Servers` | external alias (pf table) | rule 3's game-server addresses |
| the never-blocked destinations | alias or aliases | rules 1 and 2: the local set; rule 1 also: the calling services' networks (see "What counts as local") |
| `Gaming_UDP_Ports` | port alias | 1–65535 minus the safe-list |
| `Gaming_TCP_Ports` | port alias | rule 2's ports |
| three floating rules | block, quick, direction in, IPv4, no interface | rules 1, 2 and 3 |

The names are fixed. When an alias with one of them exists and is not the plugin's,
gaming blocking stays off with a clear error in status, and the internet switch and the
messaging block keep working. The internet alias name may not take any of these names
(the model rejects them, as it rejects the messaging names).

The objects are made on first use, like the messaging objects: while the plugin is
enabled and some enabled device has Gaming set to anything but Not blocked. The device
table is synced on every run. The port aliases' contents come from data shipped with the
plugin and are rewritten when they differ, so a plugin update can change the safe-list.

## Dropping open connections

This follows the existing "Drop existing connections" setting. When a device starts being
denied gaming, drop exactly the states that one of the three rules would now block: UDP
to a non-local address on a blocked port, TCP to a game port, and anything to a game
server. Do the same when the game-servers table gains addresses while devices are denied.
Never touch any other state. This is best effort and never fails the sync.

## Status and UI

- Device dialog: the Gaming field after Messaging, with the help text above.
- Devices grid: a "Gaming" column.
- Status tab: a "Gaming" column per device with blocked or allowed and the reason, and a
  summary line: in use or not, the number of game-server addresses, the last refresh, and
  any errors.
- The `parentalcontrol status` JSON gains per-device `gaming`, `gaming_blocked` and
  `gaming_reason`, and a top-level `gaming` object (in use, error, table names, server
  count, last refresh, errors). Existing keys keep their meaning.
- Dashboard widget and Settings tab: unchanged.

## Uninstall

The feature only adds: aliases, three rules, table contents, a cache file, and one field
in the plugin's own config subtree. Uninstall removes them in the existing safe order and
under the existing lock, with the reference check extended to the new aliases. `--purge`
removes the new field with the rest of the subtree.

## Known limits (to be stated in the README)

- IPv4 only.
- Not blocked: browser games and other games that play over ordinary web traffic, apart
  from the ones listed; Minecraft Java servers on a port other than 25565; consoles;
  cloud-gaming services that stream through call relays or port 443; Photon games and
  voice services (Agora and Tencent's web SDKs) that fall back to port 443.
- Minecraft Bedrock friend worlds and Xbox-world joins fall back to Microsoft's call
  relays, which Teams also uses, so the network cannot block them. The fix is the
  multiplayer setting in the child's Microsoft account (Xbox privacy settings).
- Game Center multiplayer uses FaceTime's ports. The fix is Screen Time on the device
  (Game Center, Multiplayer Games).
- HoYoLAB is not blocked: it runs entirely on shared CDNs, so only a DNS filter can block
  it. Genshin Impact, Honkai: Star Rail and Zenless Zone Zero still open and sign in;
  they cannot enter the game world. Cloud-streamed versions such as Cloud Genshin may
  still work.
- A game server that happens to listen on a safe-listed port gets through: a few ports
  out of each game's range (for example Zoom's 8801–8810 inside Rocket League's
  7000–9000, Signal's 10000, and the call-relay ports inside Fortnite's 12000–65000).
- Calls lose their direct phone-to-phone path, since that goes to random ports on the
  other person's home connection, and go through the app's relay servers instead, which
  can lower quality. An app with neither a network of its own nor published ports
  (Snapchat) relies on its relays answering on 3478.
- VPN apps that use UDP (WireGuard, OpenVPN) stop while gaming is blocked. IKEv2 and
  TCP 443 VPNs keep working, and a game played through them is not blocked.
- Other apps that send UDP to ports outside the safe-list stop while gaming is blocked:
  speed-test latency checks, peer-to-peer apps, some softphones.
- Discord voice can fail on servers outside Discord's own address block.
- Mobile data and other networks are out of reach.
- A device connected to the home VPN reaches the firewall from its tunnel address, not
  its home address. To cover it, add the tunnel address as its own device with the same
  settings. This holds for the internet and messaging blocks too.

## Verification: what "tested" means

1. Table-driven tests that run without OPNsense: the gaming decision; the complement
   port list (together with the safe-list it covers exactly 1–65535, with no overlap,
   and in order); the local set; the choice of states to drop for each rule; the data
   file's checks; existing devices loading as Not blocked. Every new test is shown to fail
   against a deliberately broken version first.
2. Static analysis as for 0.2.0, with no new findings beyond the known ones.
3. The virtual-machine uninstall test, extended to the gaming objects and settings, and
   both upgrade paths: from 0.0.2 (where the installer must run twice) and from the 0.2.0
   build the real firewall runs today.
4. On the real firewall, with the reference gaming phone and a second Android phone (an
   iPhone too, if available):
   - The reference phone sometimes runs a WireGuard tunnel to the home network, and its
     traffic then arrives from its tunnel address, which no device entry covers. During
     the test its VPN is off, or its tunnel address is added as a second device with the
     same settings.
   - Blocked: Clash of Clans cannot connect within a minute, and a match in progress
     drops. A real-time UDP game installed for the test cannot join a match. Pokémon GO
     cannot connect.
   - Still working:
     - voice and video calls, both ways, in WhatsApp, Messenger and Instagram, and in
       every other messaging app the test phones have an account for (Viber, Snapchat,
       Telegram, LINE, KakaoTalk, Signal);
     - FaceTime (on the iPhone);
     - an ordinary phone call over Wi-Fi Calling;
     - web, YouTube, an app-store download and notifications;
     - Discord text and voice;
     - the VPN back home.
   - The firewall's rule counters, read during the calls, show the gaming rules matching
     nothing but game traffic.
   - Not blocked: everything comes back at once. Blocked outside allowed hours: the block
     flips at the edge of the window. Status agrees throughout, and the block survives a
     reboot.
   - Calls come first. If a messaging app's calls fail while gaming is blocked:
     - an app with documented call ports gets them back on the safe-list (Viber: 4244,
       5242, 5243, 7985);
     - an app with neither documented ports nor a network of its own (Snapchat) gets its
       relay host names, found by a packet capture, added to a list that the gaming
       refresh resolves on every sync, and their addresses are exempted from rule 1.

     Every port this reopens is added to "Known limits". The change is recorded under
     "Changes made during implementation". An app whose calls still cannot be made to
     work goes to the maintainer before release.
5. A config-side uninstall-and-resync rehearsal on the real firewall, as for messaging.

The builder runs 1 and 2, writes the script for 3, and writes 4 and 5 as step-by-step
checks. Steps 3 and 4 need the maintainer's hands.

## Left to the builder

- Code structure: whether the messaging decision, objects and state drops become a
  general "category" or the gaming code runs in parallel.
- The gaming data file's format, its tests, and how the Discord block is sourced (shipped
  as is, or rebuilt from the RIPE database at release time by netname).
- How the never-blocked destinations are made (one external table filled on every sync,
  or an alias plus a table), how the firewall's own addresses are read, and which parts of
  the messaging data feed the calling networks (its shipped ranges and Meta's site
  addresses, not its learned addresses).
- Pokémon GO's exact host names, checked on a device.
- Model version and any migration, provided existing devices load as Not blocked.
- Wording beyond what is fixed here, and the release number. Releasing needs the
  maintainer's explicit go-ahead.
- Confirming early: that a filter rule accepts a port alias of ranges together with an
  inverted destination alias, how many pf rules the port alias expands to, and that the
  port alias is rewritten safely while rules use it.

## Calls made during design

- UDP is blocked by default with a safe-list of non-game ports, written as a complement
  port alias rather than pass rules.
- The safe-list is kept as short as possible, because every port on it is a hole: no
  Viber, Webex or TURN-over-DTLS ports, no DNS over QUIC, no Game Center-only ports, no
  Discord range.
- Calling services that own their networks are exempted by address instead of by port:
  Discord's block, and the messaging apps' networks from the messaging data, whichever
  apps are selected for messaging.
- Rule 2 takes only distinctive TCP ports with publisher or engine documentation, plus
  the default ports of common game-server software. Ports known only from secondary
  lists wait for a packet capture.
- Vivox's published ranges join rule 3, so in-game voice chat stops too.
- Calls come before closing every last port: an app whose calls fail gets its ports or
  relays back, and the reopened holes are documented.
- A device on the home VPN is covered by adding its tunnel address as a second device; no
  model change for several addresses per device.
- Rule 3 lists Pokémon GO, Honkai Impact 3rd, Infinity Nikki and Love and Deepspace: the
  named publishers' games that have servers of their own. Global servers only; no China
  builds. The publishers' download servers are not blocked.
- Genshin Impact, Honkai: Star Rail and Zenless Zone Zero rely on rule 1 alone, and
  HoYoLAB stays unblocked, because their hosts sit on shared front ends.
- Non-internet destinations and the firewall's own addresses count as local.
- Messaging's decision, server-list machinery, state drops and uninstall are reused, and
  Mode and Override stay out of the decision, as for messaging.
- Breaking speed-test UDP checks and UDP VPN apps is accepted.
