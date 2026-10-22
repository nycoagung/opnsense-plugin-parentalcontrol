# Gaming block: device checklist

This is the hands-on part of the gaming block's verification: steps 4 and 5 of
"Verification" in the design spec. The VM script, `tests/vm/uninstall-test.sh`,
already covers the firewall objects, the port aliases and tables, a dropped
connection to a game server, the schedule and uninstalling. This list covers what
only real devices and real games can show.

Work through it on the firewall with the test devices in hand, and tick each line
or note what happened instead. Placeholders:

- `<device-ip>`: the test device's address. Use a phone that has live games
  installed, then a second Android phone, and an iPhone or iPad if there is one.
- `<other-device-ip>`: a second device that stays unblocked, to call and to play
  from.
- `<window-end>` and `<window-start>`: times a few minutes from now, as HH:MM.
- `<branch>`: the branch being tested.

Before starting:

- **Have the games ready on the test device:** Clash of Clans or another Supercell
  game, which plays over its own TCP port; one real-time game that plays over UDP,
  such as Brawl Stars, Roblox or Among Us; and Pokémon GO and Monster Hunter Now if
  they are installed. Open
  each once before the block, so that a failure later is the block's.
- **Take the test device out of any DNS filtering** that blocks game names, such as
  a Pi-hole group with blocklists. The plugin learns the game servers' addresses
  from DNS on the firewall, but a device whose lookups fail never reaches them
  either, and the results would describe the filter.
- **A device that runs a VPN back home** reaches the firewall from its tunnel
  address, which no device entry covers. Turn that VPN off for the test, or add the
  tunnel address as a second device with the same settings.

Commands are run on the firewall's console (menu option 8) or over SSH. Each one
works in the default root shell (csh) as well as in sh.

## 1 Install the branch build

Bootstrap from codeload with the branch as the ref:

    fetch -qo /tmp/pc.tgz https://codeload.github.com/nycoagung/opnsense-plugin-parentalcontrol/tar.gz/refs/heads/<branch> && rm -rf /tmp/pcx && mkdir -p /tmp/pcx && tar -xzf /tmp/pc.tgz -C /tmp/pcx && sh /tmp/pcx/opnsense-plugin-parentalcontrol-<branch>/install.sh

If the plugin is already installed, its own installer can fetch the branch instead:

    env GH_REF=<branch> sh /usr/local/opnsense/scripts/OPNsense/ParentalControl/install.sh

One run is enough from 0.1.0, 0.2.0 or 0.3.0. On a firewall that still runs 0.0.2,
run that line twice (see "Upgrading from 0.0.2" in the README).

- [ ] The installer ends with `done - reload the GUI, then see Firewall > Parental Control`.
- [ ] After reloading the GUI, Firewall > Parental Control opens without an error.
- [ ] Every existing device shows Gaming as Not blocked.
- [ ] The Games tab lists the games `gaming.json` ships, every one switched on.

## 2 The page

- [ ] Devices tab: the grid has a **Gaming** column after Messaging.
- [ ] The device dialog shows **Gaming** directly below **Messaging**, with three
      choices: Not blocked, Blocked, Blocked outside allowed hours, and the help
      text "Blocks live online games. Calls, Wi-Fi Calling, web, video and app
      downloads keep working. ..."
- [ ] The Days help mentions Gaming beside Messaging.
- [ ] Status tab: a **Gaming** column for each device, with a badge and the reason.
- [ ] With no enabled device using gaming, the summary line says "Gaming: not in use".

## 3 Block the test device

1. On the test device, start a match in the real-time game and keep playing.
   Leave Clash of Clans open on its village.
2. Under Devices, edit the device at `<device-ip>`. Set Mode to Always allowed and
   Messaging to Not blocked, so that neither the internet schedule nor the
   messaging block makes a check pass or fail for the wrong reason. Set Gaming to
   Blocked, save, then press Apply.

- [ ] Status: the device's Gaming badge says blocked, with the reason "gaming
      always blocked".
- [ ] Right after Apply, the summary line says "Gaming: N game-server entries ·
      refreshed HH:MM". On the first use, between saving the device and pressing
      Apply, it also shows, in red, "gaming objects missing: " and the objects; the
      sync that Apply runs creates them and the red text goes.
- [ ] `pfctl -t NoGaming -T show` lists `<device-ip>`.

`Gaming_Exempt` holds thousands of entries in address order, so test it rather
than read it. The first command tries an address in each local network, multicast
and broadcast; the second tries every address of the firewall's own:

    pfctl -t Gaming_Exempt -T test 10.1.2.3 172.16.1.2 192.168.77.7 169.254.1.2 239.255.255.250 255.255.255.255
    pfctl -t Gaming_Exempt -T test `ifconfig -a | awk '$1 == "inet" {print $2}' | grep -v '^127\.'`

- [ ] The first answers `6/6 addresses match.`
- [ ] The second answers `N/N addresses match.`, N being the number of addresses it
      tried.

## 4 The games, while blocked

Each should fail within a minute of Apply. With "Drop existing connections" on, as
it is by default, a match in progress ends rather than carrying on.

- [ ] The real-time game's match drops, and a new match cannot be joined.
- [ ] Clash of Clans loses its connection to the village and cannot reconnect.
- [ ] Pokémon GO and Monster Hunter Now cannot connect (if installed).
- [ ] Another Niantic game, such as Pikmin Bloom, Peridot or Ingress, stops at
      sign-in (if installed).
- [ ] Each other game the device has from the plugin's lists cannot connect: Whiteout
      Survival, Kingshot, eFootball, Last War, MONOPOLY GO!, Gossip Harbor, Heart of
      Vegas, Lightning Link, Cashman Casino.
- [ ] For each game, note what it shows: an error, a retry loop, a spinner.

## 5 Still working on the blocked device

Calls come first. Before the calls, note the packet counts of the three gaming
rules. pf labels each rule with its uuid rather than its description, and expands
the UDP rule into one rule per port range and interface, so this command reads the
labels from `/tmp/rules.debug` and adds up each rule's counts. (The statistics
under Firewall > Rules are cached for five minutes, too long for a before and
after.)

    pfctl -sr -v | awk 'NR == FNR { if (index($0, "# Parental Control: block gam") && match($0, /label "[^"]+"/)) d[substr($0, RSTART + 7, RLENGTH - 8)] = substr($0, index($0, "block gam")); next } /^[^ ]/ { r = ""; if (match($0, /label "[^"]+"/)) r = d[substr($0, RSTART + 7, RLENGTH - 8)]; if (length(r)) n[r] += 0; next } length(r) { for (i = 1; i < NF; i++) if ($i == "Packets:") n[r] += $(i + 1) } END { c = 0; for (k in n) { print n[k], "packets:", k; c++ }; if (c < 3) print "only", c, "of the 3 gaming rules found" }' /tmp/rules.debug -

It prints a line per rule, such as `13 packets: block gaming UDP`. If it says fewer
than 3 rules were found, stop: the gaming rules are not loaded.

- [ ] A WhatsApp voice call and a video call with `<other-device-ip>`, both ways,
      connect and hold for a minute.
- [ ] A Messenger call and an Instagram call connect.
- [ ] The calls of every other messaging app the device has an account for
      connect: Viber, Snapchat, Telegram, LINE, KakaoTalk, Signal. Note any not
      tested.
- [ ] FaceTime connects (on the iPhone or iPad).
- [ ] An ordinary phone call over Wi-Fi Calling connects (turn mobile data off,
      or check the status bar shows Wi-Fi Calling).
- [ ] Web browsing, YouTube, an app-store download and notifications all work.
- [ ] The local network works: casting or AirPlay to a TV or speaker, printing, or
      a smart-home app reaching a device at home, whichever the device uses.
- [ ] The device renews its address and keeps the internet: on an iPhone or iPad,
      Settings > Wi-Fi > the network's (i) > Renew Lease; on Android, Wi-Fi off and
      on again.
- [ ] A game played over the local network, such as a Minecraft world opened to LAN
      on `<other-device-ip>`, can be joined (if the device has one): only online
      games are blocked.
- [ ] Discord text works, and a Discord voice channel connects (if installed).
- [ ] A VPN back home, if the device uses one, connects; turn it off again after.
- [ ] After the calls, run the counting command again: the UDP rule's count has
      grown by a handful at most, as calls do not go through it.

If a call fails:

1. Note the app and what happened.
2. Turn on logging for the rule "Parental Control: block gaming UDP" (Firewall >
   Rules > Floating, the rule's Log box) and make the call again. Firewall > Log
   Files > Live View, filtered on `<device-ip>`, shows the UDP destination ports
   the app tried.
3. Turn the logging off again.
4. Record the ports with the app. Following the design's "calls come first", the
   app's documented ports go back on the safe-list, or its relay servers are
   exempted, before the release.

## 6 Other devices

- [ ] A device not denied gaming plays the same games normally throughout.

## 7 Back to Not blocked

Set Gaming to Not blocked and press Apply.

- [ ] The games connect again within a minute.
- [ ] `pfctl -t NoGaming -T show` is empty.
- [ ] With no other device using gaming, the summary says "Gaming: not in use".

## 8 Follow schedule

Set Gaming to Blocked outside allowed hours, Days to every day, and Internet
allowed from `<window-start>` until `<window-end>`, a window that starts a few
minutes from now. Keep Mode at Always allowed.

- [ ] Before `<window-start>` the games fail and the status reason says
      "gaming: outside allowed hours".
- [ ] Within a minute of `<window-start>` the games work and the reason says
      "gaming: within allowed hours".
- [ ] Within a minute of `<window-end>` they fail again.

## 9 Reboot

With the device on Blocked, reboot the firewall.

- [ ] After the reboot, the games still fail on the device, and calls and the local
      network still work.
- [ ] Both `pfctl -t Gaming_Exempt -T test` commands of section 3 answer as they
      did there, and `pfctl -t NoGaming -T show` lists `<device-ip>`.

## 10 Status page throughout

- [ ] At every step above, the Status tab agreed with what the device did.
- [ ] No red error line stayed on the summary after an Apply.

## 11 Config-side uninstall rehearsal

With the device still on Blocked, remove the plugin's firewall objects; its
settings stay:

    configctl parentalcontrol uninstall

- [ ] It lists what it removed, a line each, among them `alias` lines for the five
      gaming aliases and `removed table` lines for NoGaming, Gaming_Servers and
      Gaming_Exempt; the gaming rules and aliases are gone from Firewall > Rules >
      Floating and Firewall > Aliases.
- [ ] `pfctl -s Tables` no longer lists NoGaming, Gaming_Servers or Gaming_Exempt.
- [ ] The games work on the device again.

Then put them back:

    configctl parentalcontrol sync

- [ ] The sync answers OK and puts them back, with the same names; the games fail
      again within a minute.
- [ ] `pfctl -s Tables` lists NoGaming, Gaming_Servers and Gaming_Exempt again.

## 12 The Games tab

With the test device still on Blocked:

- [ ] Each game's row says what it is blocked by, such as "2 hosts" or "2 ranges",
      and, within a minute, what it is blocking, such as "12 addresses", or why
      nothing, such as "no answer".
- [ ] The Status summary's gaming part ends with how many games are on.

Switch off a game that shows addresses, one the test device has installed:

- [ ] Within a minute its row says "switched off", and the Status summary's
      game-server entries drop by about its count (less any address another game
      also gives).
- [ ] The game connects on the test device. Switch it on again: within a minute it
      fails again, and the entries are back.

Add a game, and try each of these in the dialog; each is refused with its message
next to the field, and nothing is saved:

- [ ] a host on a shared hosting service, such as `x.web.app`: "x.web.app is on a
      shared hosting service, so blocking it would block other sites too."
- [ ] the range `10.0.0.0/8`: "10.0.0.0/8 is not a public IPv4 network."
- [ ] a range inside one of the shared CDNs' ranges (`shared_cdn_ranges` in
      `messaging.json`): "… overlaps a shared service that is never blocked."
- [ ] a name another game has, in other letters' case: "Another game already has
      this name."
- [ ] a host another game lists: "… is already listed for <that game>."
- [ ] an address among the hosts: "… is not a host name. An address belongs under
      Address ranges."

Then save a game whose only host is a website served through a CDN:

- [ ] Within a minute its row says "answers through a CDN or shared front end",
      and the Status summary's game-server entries do not change.
- [ ] Delete it: it leaves the list, and the entries still do not change.

Run the installer a second time (`configctl parentalcontrol install`):

- [ ] No game appears twice, and the games keep their settings.
- [ ] The messaging summary's server entries are the same as before this section.

## Recording the results

Note the date, the branch and commit, the devices and games used, and every line
that did not pass, with what happened instead. The results go into the README's
"What has been verified" section.
