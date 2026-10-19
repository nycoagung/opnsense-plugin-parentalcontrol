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
  such as Brawl Stars, Roblox or Among Us; and Pokémon GO if it is installed. Open
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

One run is enough from 0.1.0 or 0.2.0. On a firewall that still runs 0.0.2, run that
line twice (see "Upgrading from 0.0.2" in the README).

- [ ] The installer ends with `done - reload the GUI, then see Firewall > Parental Control`.
- [ ] After reloading the GUI, Firewall > Parental Control opens without an error.
- [ ] Every existing device shows Gaming as Not blocked.

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
- [ ] `pfctl -t Gaming_Exempt -T show | head -20` shows the local networks
      (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 169.254.0.0/16, 224.0.0.0/4,
      255.255.255.255) and the firewall's own addresses.

## 4 The games, while blocked

Each should fail within a minute of Apply. With "Drop existing connections" on, as
it is by default, a match in progress ends rather than carrying on.

- [ ] The real-time game's match drops, and a new match cannot be joined.
- [ ] Clash of Clans loses its connection to the village and cannot reconnect.
- [ ] Pokémon GO cannot connect (if installed).
- [ ] For each game, note what it shows: an error, a retry loop, a spinner.

## 5 Still working on the blocked device

Calls come first. Before the calls, note the packet counts of the three gaming
rules: `pfctl -vvsr | grep -A2 'block gaming UDP\|block gaming TCP\|block game servers'`.

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
- [ ] Discord text works, and a Discord voice channel connects (if installed).
- [ ] A VPN back home, if the device uses one, connects; turn it off again after.
- [ ] After the calls, the UDP rule's packet count has grown by a handful at most:
      calls do not go through it.

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

- [ ] After the reboot, the games still fail on the device and calls still work.
- [ ] `pfctl -t Gaming_Exempt -T show | head -5` lists the local networks, and
      `pfctl -t NoGaming -T show` lists `<device-ip>`.

## 10 Status page throughout

- [ ] At every step above, the Status tab agreed with what the device did.
- [ ] No red error line stayed on the summary after an Apply.

## 11 Config-side uninstall rehearsal

With the device still on Blocked:

    configctl parentalcontrol uninstall && configctl parentalcontrol sync

- [ ] The first command answers OK; the gaming rules and aliases are gone from
      Firewall > Rules > Floating and Firewall > Aliases, and the games work.
- [ ] The sync answers OK and puts them back, with the same names; the games fail
      again within a minute.
- [ ] `pfctl -s Tables` lists NoGaming, Gaming_Servers and Gaming_Exempt again.

## Recording the results

Note the date, the branch and commit, the devices and games used, and every line
that did not pass, with what happened instead. The results go into the README's
"What has been verified" section.
