# Messaging block: device checklist

This is the hands-on part of the messaging block's verification: steps 4 and 5 of
"Verification" in the design spec. The VM script, `tests/vm/uninstall-test.sh`,
already covers installing, the firewall objects, dropped connections, the schedule
and uninstalling. This list covers what only a real device can show.

Work through it on the firewall with the test device in hand, and tick each line or
note what happened instead. Placeholders:

- `<device-ip>`: the test device's address, ideally one iPhone or iPad and one
  Android phone in turn
- `<other-device-ip>`: a second device that stays unblocked; an Apple device, so
  that it can send iMessage and FaceTime
- `<window-end>` and `<window-start>`: times a few minutes from now, as HH:MM
- `<branch>`: the branch being tested

Take the test device out of any DNS filtering first, such as a Pi-hole group with
blocklists. An app whose names do not resolve falls back to addresses built into it,
which the plugin never sees, so the results would describe the filter, not the
plugin.

Commands are run on the firewall's console (menu option 8) or over SSH. Each one
works in the default root shell (csh) as well as in sh.

## 1 Install the branch build

Bootstrap from codeload with the branch as the ref (`GH_REF=<branch>`):

    fetch -qo /tmp/pc.tgz https://codeload.github.com/nycoagung/opnsense-plugin-parentalcontrol/tar.gz/refs/heads/<branch> && rm -rf /tmp/pcx && mkdir -p /tmp/pcx && tar -xzf /tmp/pc.tgz -C /tmp/pcx && sh /tmp/pcx/opnsense-plugin-parentalcontrol-<branch>/install.sh

If the plugin is already installed, its own installer can fetch the branch instead:

    env GH_REF=<branch> sh /usr/local/opnsense/scripts/OPNsense/ParentalControl/install.sh

On a firewall that still runs 0.0.2, run that line twice. The first run is 0.0.2's
installer, which copies only 0.0.2's files: every sync then fails until the second
run, made by the new installer, completes the install. From 0.1.0 on, one run is
enough: the installed copy hands over to the installer it downloads. The bootstrap
line above needs only one run.

`configctl parentalcontrol install` cannot do this: it always fetches `main`.

- [ ] The installer ends with `done - reload the GUI, then see Firewall > Parental Control`.
- [ ] After reloading the GUI, Firewall > Parental Control opens without an error.

## 2 The page

- [ ] Devices tab: the grid has a **Messaging** column.
- [ ] The device dialog shows **Messaging** directly below **Mode**, with three
      choices: Not blocked, Blocked, Blocked outside allowed hours.
- [ ] Its help text says that blocking also stops every notification on the device,
      including iMessage and incoming FaceTime calls.
- [ ] Settings tab: **Messaging apps to block** is a token field holding all eight
      apps: Facebook & Instagram, KakaoTalk, LINE, Signal, Snapchat, Telegram,
      Viber and WhatsApp. After an upgrade from 0.1.0 it holds the apps saved
      before plus the three new ones; a field that was saved empty stays empty.
- [ ] Its help text says that Facebook & Instagram blocks the whole Facebook,
      Instagram and Messenger apps, and that KakaoTalk also blocks other Kakao
      services.
- [ ] The Settings tab has no Save button. Changing a setting shows the reminder
      to apply on the Apply bar, and Apply saves the settings, then applies them.
- [ ] On the Devices tab, with `NoMessaging` typed as the Alias name under
      Settings, Apply brings up the Settings tab with that field marked, and
      nothing is applied. Put the old name back and press Apply.
- [ ] Status tab: a **Messaging** column for each device, with a badge and the
      reason.
- [ ] With no enabled device using messaging, the summary line says
      "Messaging: not in use".

## 3 Block the test device

1. On the test device, open a chat in each app on the list that it has, and leave
   it open. In one of them, say WhatsApp, start a voice call with the second device
   and keep it going through Apply.
2. Under Settings, keep all eight apps selected.
3. Under Devices, edit the device at `<device-ip>`. Set Mode to Always allowed, so
   that its internet schedule cannot cut everything and make a check pass or fail
   for the wrong reason. Set Messaging to Blocked, save, then press Apply.

- [ ] Status: the device's Messaging badge says blocked, with the reason
      "messaging always blocked".
- [ ] Right after Apply, the summary line says "Messaging: N server entries ·
      refreshed HH:MM": Apply runs a sync, which refreshes the list. "not refreshed
      yet" appears only on the first use, between saving the device and pressing
      Apply, or for up to a minute when the device was changed some other way.
- [ ] On the first use, between saving the device and pressing Apply, the summary
      also shows, in red, "messaging objects missing: " and the four objects. That
      is expected: the sync that Apply runs creates them, and the red text goes.
- [ ] `pfctl -t NoMessaging -T show` lists `<device-ip>`.

## 4 Each app, while blocked

Everything here should fail within a minute of pressing Apply. The chats opened in
section 3 should stop updating; that needs "Drop existing connections" on, which is
the default.

| App      | Sending fails | Receiving fails | Voice and video calls fail | An open chat drops |
|----------|---------------|-----------------|----------------------------|--------------------|
| WhatsApp |               |                 |                            |                    |
| Telegram |               |                 |                            |                    |
| Signal   |               |                 |                            |                    |
| Snapchat |               |                 |                            |                    |
| LINE     |               |                 |                            |                    |

- [ ] The call started in section 3: note whether its audio stopped at once when
      Apply cut messaging, or only after a few seconds (how many).
- [ ] Five minutes later, WhatsApp still cannot send or receive. Once its main chat
      server is blocked it moves to a fallback within a minute or two, so a check
      made at once proves less.

### LINE

- [ ] LINE's other services fail too. That is expected: LINE is blocked by its own
      network.

### Viber

- [ ] Sending and receiving fail, and an open chat stops updating.
- [ ] Calls: note whether a voice or video call still connects, and whether its
      sound passes. Viber's call relays could not all be identified, so a call may
      still connect; that is a known limit.

### KakaoTalk

- [ ] Sending and receiving fail, and an open chat stops updating.
- [ ] Voice and video calls fail.
- [ ] Daum and Kakao Map fail too. That is expected: they run on Kakao's own
      networks, which the block covers.

### Facebook & Instagram

- [ ] The Facebook app's feed does not load.
- [ ] Messenger: sending, receiving and calls fail.
- [ ] Instagram: the feed does not load, and direct messages fail.
- [ ] Threads does not load. That is expected: it runs on Instagram's servers.
- [ ] Facebook Lite and Instagram Lite, if the device has them, do not load either.
- [ ] In a browser, facebook.com, messenger.com and instagram.com do not load.

## 5 Notifications on the blocked device

- [ ] No notification of any kind arrives. Send test messages from the second device
      in several apps, including apps that are not on the list.
- [ ] An iMessage from the second Apple device is not delivered.
- [ ] A FaceTime call from the second Apple device does not ring.
- [ ] On Android, no push notification arrives.
- [ ] Ordinary phone calls and SMS over the mobile network still work, if the device
      has a SIM. They do not go through the firewall.

## 6 Still working on the blocked device

- [ ] Web browsing
- [ ] YouTube
- [ ] iCloud, such as Photos syncing
- [ ] The app store (App Store or Google Play): browse and download an app
- [ ] Microsoft Teams: a chat and a call

## 7 Other devices

- [ ] The second device at `<other-device-ip>`, which is not blocked, keeps
      receiving notifications throughout sections 4 to 6, from the same apps.
- [ ] Its apps keep working.

## 8 Back to Not blocked

Set the test device's Messaging to Not blocked and press Apply.

- [ ] Within a minute every app sends, receives and calls again.
- [ ] Notifications resume. Some held back during the block may arrive at once.
- [ ] `pfctl -t NoMessaging -T show` no longer lists `<device-ip>`.
- [ ] Status: the badge says allowed, with the reason "messaging allowed".

## 9 Follow schedule

Set Messaging to "Blocked outside allowed hours". Make sure Days includes today, set
Internet allowed from to now and until to `<window-end>`, and press Apply. Mode
stays Always allowed, so the window steers only messaging, not the device's internet.

- [ ] Before `<window-end>` the apps work.
- [ ] Within a minute after `<window-end>` the apps are blocked and notifications
      stop.
- [ ] Set Override to Force allow and press Apply. Messaging stays blocked: Override
      steers only the whole-internet switch. Set Override back to No override.
- [ ] Set Internet allowed from to `<window-start>` and until to a time after it,
      then press Apply. Within a minute after `<window-start>` the apps work again.

## 10 Choosing apps

With the test device set to Blocked:

- [ ] Deselect one app under Settings, for example Telegram, and press Apply.
      Within about a minute that app works again, and the others stay blocked.
- [ ] Deselect every app, press Apply, then reload the page. The field stays empty;
      it is not refilled with the default apps.
- [ ] With no app selected, the apps work on the blocked device, but its
      notifications are still cut.

### Only Facebook & Instagram selected

Select only Facebook & Instagram and press Apply.

- [ ] WhatsApp works: messages and calls.
- [ ] Facebook, Instagram and Messenger stay blocked.

### Only WhatsApp selected

Select only WhatsApp and press Apply.

- [ ] Facebook works.
- [ ] Instagram works, including its direct messages, and its images and videos
      load.
- [ ] Messenger works: messages and calls.
- [ ] Messenger signs in for the first time: sign out of Messenger, or install it
      fresh, then sign in and open a chat. Its one-time setup of encrypted chats
      may use a WhatsApp server; note whether the sign-in and the chat work.
- [ ] WhatsApp stays blocked, also five minutes later: messages and calls.

### All eight again

- [ ] Select all eight apps again and press Apply.

## 11 Reboot

With the test device set to Blocked, reboot the firewall. Within a minute after it
is back up:

- [ ] `pfctl -t NoMessaging -T show` lists `<device-ip>`.
- [ ] `pfctl -t Messaging_Servers -T show | wc -l` is not zero.
- [ ] The apps are blocked again on the device.

## 12 Status page throughout

- [ ] At every step above, the Status tab agrees with what the device shows: the
      Messaging badge and reason for each device.
- [ ] The summary line matches the situation, followed by any lookup or download
      errors:
      - "not in use" when no enabled device uses messaging;
      - "refreshed HH:MM" once a sync has run while messaging is in use, which
        Apply does at once;
      - "not refreshed yet" only on the first use, before Apply or the next
        minute's sync, together with a red "messaging objects missing: …",
        which is expected until that sync creates them.
- [ ] If the errors include "notification range … holds protected address …",
      note the range and the address: that notification range stays blocked by
      design (the notification cut wins), so check whether the protected service
      it names (for example iCloud) still works on the blocked device.

## 13 Config-side uninstall rehearsal

This is spec step 5. Take the inventory of the plugin's objects, remove them from
the configuration, let a sync put them back, and take the inventory again. Wait a
minute before the second inventory: core refreshes the plugin's local-networks
alias in the background after the sync reloads the rules.

    pfctl -s Tables > /tmp/inventory-before.txt
    grep -c 'Parental Control:' /conf/config.xml >> /tmp/inventory-before.txt
    configctl parentalcontrol uninstall && configctl parentalcontrol sync
    sleep 60
    pfctl -s Tables > /tmp/inventory-after.txt
    grep -c 'Parental Control:' /conf/config.xml >> /tmp/inventory-after.txt
    diff /tmp/inventory-before.txt /tmp/inventory-after.txt && echo same

- [ ] `configctl parentalcontrol uninstall` lists what it removed, and the sync
      answers `OK`.
- [ ] The last command prints `same`.
- [ ] The test device is blocked again: `pfctl -t NoMessaging -T show` lists
      `<device-ip>`.

## Recording the results

Note the date, the firmware version and the devices' models and OS versions with
the results. Leave out device names, addresses and anything else about the network
from anything that is committed or shared.
