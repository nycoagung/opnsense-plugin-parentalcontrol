# More messaging apps: Viber, KakaoTalk, Facebook & Instagram

Draft, 2026-10-19 · builds on the messaging block (`2026-10-18-messaging-block-design.md`, release 0.1.0) · target release 0.2.0.

## Why

The maintainer wants three more entries under **Messaging apps to block**: Viber, KakaoTalk, and Messenger with Instagram's direct messages. Blocking stays by destination address, without Pi-hole. Messenger and Instagram's messages run on the same servers as the rest of Facebook and Instagram, so they cannot be blocked on their own. The maintainer chose: **block the whole Facebook and Instagram apps during messaging-blocked time**, and **all three new entries are selected by default**.

## Goals

1. Settings › Messaging apps to block offers **Viber**, **KakaoTalk** and **Facebook & Instagram** next to the existing five, and all eight are selected by default.
2. While a device is denied messaging and an entry is selected, that app stops working on the device: messages and calls; for Facebook & Instagram, the whole Facebook, Instagram and Messenger apps.
3. WhatsApp stays a separate entry. Selecting Facebook & Instagram never blocks WhatsApp, and selecting WhatsApp alone never blocks Facebook or Instagram (the 0.1.0 guarantee).
4. Everything else behaves as in 0.1.0: the per-device setting and schedule, the notification cut, status, uninstall.
5. No new cron job, configd action or port rule. The configd action file stays byte-identical to 0.0.2.

## Non-goals

- Instagram-only or Messenger-only switches: Instagram's messaging runs on Facebook's endpoints, so they cannot be separated.
- Discord, Kik, WeChat, Google Chat/Meet and Teams: shared infrastructure, as in 0.1.0.
- Port rules for the new apps. None is needed: the messaging rule already blocks every protocol to a blocked address. KakaoTalk's ports collide with Apple push (5223) and POP3S mail (995) and must never be blocked.
- IPv6, as in 0.1.0.
- Meta's media caches inside Australian ISPs. Blocking the API and realtime hosts already stops the apps.
- Viber's built-in proxy setting, which gets around any address block. It is a documented limit.

## Anti-goals

- Blocking WhatsApp when only Facebook & Instagram is selected, or Facebook and Instagram when only WhatsApp is.
- Any port rule.
- Shipping an address range that the app's company does not own.

## What each app needs (from the 2026-10-19 probe)

All three are blocked by the existing machinery: host names resolved every minute with the 7-day memory, plus shipped ranges where an app has them. An app with shipped `ranges` and no `ranges_url` already uses its shipped ranges (`refreshPublished()`), so no run-time change is needed for ranges.

**Viber** (id `viber`, label `Viber`)
- Hosts: `aloha.viber.com`, `aloha46.viber.com` (the chat entry point, a Viber-registered address block), `api.viber.com`, `fast.viber.com`, `media.api.viber.com` (Viber's load balancers on shared Amazon EC2).
- Shipped ranges: the Viber-registered networks in ARIN's registry (15 networks, about 2,000 addresses, including one in Sydney), built at release time from ARIN Whois-RWS by network name (`AWS-VIBER-MEDIA`, `AWS-VIBER`).
- Never list `noc-vo.viber.com` (a help desk on Cloudflare).

**KakaoTalk** (id `kakaotalk`, label `KakaoTalk`)
- Hosts: `booking-loco.kakao.com`, `ticket-loco.kakao.com`, `katalk.kakao.com`, `talk-pilsner.kakao.com`.
- Shipped ranges: KakaoTalk's chat and call servers are handed out as bare addresses at check-in, never through DNS, so the app is only blockable by Kakao Corp's address space. Ship the announced IPv4 prefixes of AS7625, AS18160, AS38099 and AS152199, merged (about 41 networks, 44,500 addresses), built at release time from RIPEstat. Leave out Kakao Games, Kakao Pay, KakaoBank and Kakao Pay Insurance, which are separate companies with their own networks.
- Collateral on a blocked device: other Kakao services (Daum, Tistory, Melon, Kakao Map, Kakao Developers, "Log in with Kakao").

**Facebook & Instagram** (id `facebook_instagram`, label `Facebook & Instagram`)
- Hosts only, no ranges: Meta's address blocks also hold WhatsApp's addresses, so ranges would take WhatsApp down. No Facebook, Instagram or Messenger address equals a WhatsApp address at any vantage point probed.
- Host list, realtime and chat first:
  - realtime and push: `edge-mqtt.facebook.com`, `mqtt-mini.facebook.com`;
  - gateway (shared by Facebook, Messenger and Instagram): `gateway.facebook.com`, `gateway.messenger.com`, `gateway.instagram.com`;
  - end-to-end chat: `chat-e2ee.facebook.com`, `chat-e2ee-mini.facebook.com`, `web-chat-e2ee.facebook.com`, `z-p42-chat-e2ee-ig.facebook.com`;
  - Facebook: `facebook.com`, `www.facebook.com`, `m.facebook.com`, `b-graph.facebook.com`, `b-api.facebook.com`, `graph.facebook.com`, `api.facebook.com`, `edge-chat.facebook.com`, `rupload.facebook.com`;
  - Messenger: `messenger.com`, `www.messenger.com`, `edge-chat.messenger.com`, `m.me`;
  - Instagram: `instagram.com`, `www.instagram.com`, `b.i.instagram.com`, `i.instagram.com`, `graph.instagram.com`, `graphql.instagram.com`, `edge-chat.instagram.com`, `iglite-z.instagram.com`;
  - Meta's fallback names (they return the other Australian site): `star.fallback.c10r.facebook.com`, `star-mini.fallback.c10r.facebook.com`, `mqtt.fallback.c10r.facebook.com`, `dgw.fallback.c10r.facebook.com`, `chat-e2ee-fallback.facebook.com`, `chat-e2ee-mini-fallback.facebook.com`, `b-fallback.i.instagram.com`, `i-fallback.instagram.com`, `graph-fallback.instagram.com`;
  - media: `scontent.xx.fbcdn.net`, `video.xx.fbcdn.net`, `scontent.cdninstagram.com`.
- Do not list Oculus/Quest names, Threads names (Threads shares Instagram's main address and is blocked by it anyway) or `*.fna.fbcdn.net` cache names.
- Collateral on a blocked device: Threads, Meta AI, meta.com and its Accounts Center, "Log in with Facebook" on other sites and apps, and signing in to a Meta Quest from that device.

## The never-block list gets owners

Today the protected names include six Meta names (`facebook.com`, `www.facebook.com`, `graph.facebook.com`, `edge-mqtt.facebook.com`, `www.instagram.com`, `i.instagram.com`), so that blocking WhatsApp never breaks Facebook or Instagram. Left as they are, they would stop the new entry from blocking anything: the guard drops exactly Facebook's and Instagram's core addresses.

- `messaging.json` gains `protected_owners`: a map from a protected host name to the app id that owns it. `protected_hosts` stays a list of strings, so the file's existing readers and checks keep working.
- The six Meta names are owned by `facebook_instagram`.
- WhatsApp's own names `g.whatsapp.net`, `web.whatsapp.com` and `mmg.whatsapp.net` join `protected_hosts`, owned by `whatsapp`, so selecting Facebook & Instagram can never take WhatsApp down.
- Each run, a protected name counts only while its owner is not among the selected apps; a name without an owner always counts. One pure function decides this. Everything after it (resolving the protected names, remembering their answers, filtering learned addresses and ranges, the status errors) is unchanged.

## Defaults and the upgrade

- The settings model goes to 1.2.0. The three ids join the app options with the labels above, and the default selection becomes all eight.
- A settings migration adds the three new ids to a stored selection that is not empty, so a firewall whose owner saved "all five" gets all eight. A stored selection that is explicitly empty stays empty. (Builder's note: this follows "selected by default" for existing installs; the maintainer can untick any of them.)
- The maintainer's own firewall runs 0.1.0 from the `messaging-block` branch; 0.2.0 installs over it with the console command, in one run.

## What people see

- The Settings help text names what the bigger entries take with them: Facebook & Instagram blocks the whole Facebook, Instagram and Messenger apps, including Threads, Meta AI and "Log in with Facebook"; KakaoTalk also blocks other Kakao services such as Daum, Tistory, Melon and Kakao Map.
- README: the offered apps; the "not offered" table loses Messenger and Viber; Known limits gain Viber's proxy setting, Viber calls that may still connect (its call relays could not all be identified), the KakaoTalk and Facebook & Instagram collateral, Meta's caches inside ISPs, and Viber's and KakaoTalk's ranges being fixed until the next release.

## Lookups

- Notification names still go first. The selected apps' names then go **in turns**: each app's first name, then each app's second name, and so on, without duplicates (builder's call: with the apps in key order, Facebook & Instagram's 42 names would push WhatsApp's to the end, so a slow resolver would skip WhatsApp first). Each app's own list keeps its order, and Facebook & Instagram's list starts with the realtime and chat names. Names skipped in a run keep their remembered addresses, as today.
- With every app selected a run looks up about 100 names. The 20-second budget for app and notification names and the 10-second budget for protected names stay as they are; names that do not fit are reported as skipped, as today.

## The data generator

- `tools/messaging-sources.json` gains, per app, optional `ranges_sources`: RIPEstat announced prefixes by AS number (KakaoTalk) and ARIN networks by name (Viber). The generator downloads them at build time, keeps IPv4, merges them, checks them (public addresses, no prefix shorter than /8, no overlap with the shared-CDN list or the notification ranges) and writes the app's `ranges`.
- `--check` also reports, for each owned protected name, whether it is protected with and without its owner selected.

## Tests

- **Data:** the three ids and labels; model options, labels and the default of all eight; every `protected_owners` key is in `protected_hosts` and every owner is an app id; the six Meta names are owned by `facebook_instagram` and the three WhatsApp names by `whatsapp`; the Kakao and Viber ranges are public, at most /8 wide, and overlap neither the shared-CDN list nor the notification ranges; the Facebook & Instagram list contains the gateway, realtime and Instagram API names.
- **Library:** the owner filter (unowned always; owned only while the owner is not selected), the in-turns lookup order, and the migration's selection update (non-empty gains the new ids once, empty stays empty, order and duplicates handled).
- **VM script:** status lists eight apps by default; the migration adds the new ids to a saved selection.
- **Device checklist:** Viber, KakaoTalk, Facebook, Instagram (feed and messages), Messenger and Threads blocked while their entry is selected; WhatsApp unaffected while only Facebook & Instagram is selected; Facebook, Instagram and Messenger (including a first-time Messenger sign-in) unaffected while only WhatsApp is selected.

## Known limits (new)

- Viber's proxy setting bypasses the block; Viber calls may still connect.
- KakaoTalk takes other Kakao services with it; Facebook & Instagram takes Threads, Meta AI, meta.com, "Log in with Facebook" and Quest sign-in with it, on blocked devices only.
- Viber's and KakaoTalk's ranges are fixed at release time.
- Messenger's one-time end-to-end-encryption setup may use `v.whatsapp.net`, which is on WhatsApp's list (from public client code, not verified on a device). With only WhatsApp selected, that setup may fail on a blocked device. Nothing changes in what is blocked (builder's call); the device checklist checks it.

## Left to the builder

The exact help wording, the generator's download code, the order inside the non-Facebook host lists, and test names.

## Branch

`more-messaging-apps`, from `messaging-block` at 80677ab. Release 0.2.0 after the maintainer's decision on 0.1.0.

## Changes made during implementation

- The generator's app-range checks (public, no prefix shorter than /8, no overlap with the shared-CDN list or the notification ranges) also run on Telegram's published list at build time, so every shipped app range meets one floor whatever its source. Telegram's list is still read as published, not merged.
- At the maintainer's request (2026-10-19): the Settings tab's Save button is gone. Apply saves the settings when they changed since they were loaded or last saved, then applies; an invalid setting stops the Apply and brings up the Settings tab with the field marked. Changing a setting shows core's reminder to apply.
