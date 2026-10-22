# Editable game servers: design

Date: 2026-10-22. Builds on 0.3.0 and on PR #2 (`publisher_domains`, `--resolver`).

## Intent

The gaming block (0.3.0) blocks the servers of a fixed list of games shipped in
`gaming.json`. A parent who finds a game the list doesn't catch, or wants to stop
blocking one, has to wait for a release or edit a file on the firewall. This adds a
**Games** section to the plugin's settings where the parent sees the list, adds a game
with its name and hosts, switches any game on or off, and edits or deletes games.
Today's shipped list is what the parent starts with.

## What the parent sees

Agreed from a mockup:

- A **Games** tab beside Devices, Settings and Status, laid out like the Devices list.
  Each row has an on/off switch, the game's name, what it is blocked by ("8 hosts",
  "2 ranges"), how many addresses it is blocking now, and edit and delete buttons. An
  **Add game** button opens the form.
- When a game blocks nothing, its row says why: its hosts got no answer, they
  answered through a CDN or a shared front end, or every answer was guarded
  (behaviour 4). While no device is denied gaming nothing is resolved, and the list
  says so instead of showing counts.
- The add/edit form: **Enabled**, **Name**, **Hosts** (one per line) and **Address
  ranges** (optional, one per line). Proxy hosts, the names reached through a
  provider's per-application proxy (today only Last War's Cloudflare game gateway),
  get their own optional field in the form, because the plugin treats their answers
  differently from plain hosts. `publisher_domains` is not shown: only the
  maintainer's release check reads it.
- The list applies to every device denied gaming. There is no per-device choice of
  games.

## Behaviour

1. **Defaults.** A new install, and an upgrade to the release that brings this,
   starts with the 13 games in `gaming.json`'s `servers`, all enabled, with their
   hosts, proxy hosts and ranges. From then on the list is the parent's.
2. **Later releases** (the maintainer's decisions):
   - the parent's list is kept: a release never changes or removes a game the list
     already holds, edited or not. A host a release fixes or withdraws reaches only
     new installs. The guards in behaviour 4 are what stop a stale shipped host
     from blocking unrelated sites.
   - games a release newly ships are added. A game is identified by its key in
     `gaming.json` (`pokemon_go`, `last_war`), so a new game is one whose key the
     list has never held. A shipped game the parent deleted does not come back.
   *(My calls on how a new game joins:)*
   - it joins switched on, as the shipped list does on a new install;
   - it joins even if the parent has deleted every game: the rule is "add new
     games", and switching gaming off on a device is how a parent stops it;
   - when it clashes with one of the parent's own games, by the same name (ignoring
     case) or by a host the parent already lists, it is not added; its key still
     counts as held, so it isn't offered again, and the clash is reported once in the
     status errors.
3. **Changes take effect at the next sync**, within a minute:
   - switching a game off, deleting it, or removing one of its hosts takes its
     addresses out of the game-servers table. Today a learned address is kept for up
     to 7 days and isn't tied to the host it came from, so the learned memory must
     know each address's host; an address goes when the last host that gave it is
     gone;
   - a new game's hosts, or a host added to a game, are resolved and blocked, under
     the same answer rules as the shipped ones: own load balancers through
     `alias_suffixes`, proxy hosts through `proxy_suffixes`, nothing in a shared CDN's
     range, no filtered answers.
4. **Guards, for every game, shipped or the parent's** (the maintainer's decision):
   - **At run time**, an address is never blocked when it:
     - is an answer of one of the messaging block's protected names (Google, Apple and
       Microsoft essentials). The gaming refresh gets the same protected-name guard as
       messaging, which today it doesn't have;
     - lies in a shared front-end range: today's `shared_cdn_ranges` (Akamai,
       Cloudflare, CloudFront, Fastly) plus Google's own front ends, Firebase Hosting,
       Vercel, Netlify and GitHub Pages, kept as data beside `shared_cdn_ranges`;
     - lies in the never-treated-as-gaming set: Discord's block and the messaging
       apps' networks.
   - **On save**, the form refuses a host under a known shared-hosting domain (kept
     as data: `googleapis.com`, `appspot.com`, `run.app`, `firebaseapp.com`,
     `web.app`, `vercel.app`, `netlify.app`, `github.io`, and the four CDNs' own
     domains), and a range that overlaps any guarded range above.
   - The shared front-end ranges must not swallow addresses that serve a single
     customer, such as Google Cloud and AWS load balancers or AWS Global Accelerator.
     Every shipped game's current addresses must stay blockable, and a data test pins
     that.
5. The list can be edited whether or not any device is denied gaming. As today,
   nothing is resolved while no device uses gaming.
6. **Checks on save** (refused with a message next to the field), besides the guards:
   - a name is required, and no two games share a name (ignoring case);
   - a game needs at least one host, proxy host or range;
   - each host or proxy host is a DNS name (stored lower-case), and no host is listed
     twice, in one game or across games;
   - each range is a public IPv4 network: it must not reach into private, local,
     carrier-grade NAT, multicast or other special-purpose space, the same rule the
     shipped ranges pass.
7. The Status tab's gaming summary counts the enabled games. *(My call.)*

## Unchanged

- The ports (the UDP safe-list and the game TCP ports), the never-blocked
  destinations, and the `alias_suffixes` and `proxy_suffixes` stay in `gaming.json`
  and are not editable.
- The existing answer rules stay; the guards add to them.
- The maintainer's release check, `tools/check-gaming-hosts.py`, keeps checking the
  shipped `gaming.json`. Hosts a parent adds are not checked by it.
- The configd action file stays byte-identical to 0.0.2's.
- No dependency on Pi-hole or any other DNS filter.

## Non-goals

- Per-device game lists, or a game schedule of its own.
- Editing the ports or the never-blocked destinations.
- Import, export or sharing of lists.
- Checking a parent's hosts against passive DNS at run time.
- Releases updating games already in the list.

## Anti-goals

These would make it a failure even if it works:

- a game switched off or deleted that is still blocked hours or days later;
- an entry that quietly blocks unrelated sites;
- an upgrade that loses or silently overwrites the parent's list;
- a game that shows as on while it blocks nothing, with no word why;
- any change to how devices, schedules or the messaging block behave.

## Constraints

- OPNsense 26.7 MVC, as the plugin is built today:
  - the model is `ParentalControl.xml` (now version 1.3.0), with migrations in
    `Migrations/` (only `M1_2_0.php` so far);
  - the API is `SettingsController` (the device actions are the pattern to follow);
  - forms live in `controllers/.../forms`, and the view is `index.volt`.
- The installer must install any new file, and uninstall must remove the plugin's
  settings, games included.
- Public repository: tests and docs use documentation values only (RFC 5737 addresses,
  `.example` names). Vendor data appears only in `gaming.json`, `messaging.json`,
  `tools/` and the data tests' pins.
- Tests are written first, in the repository's own style: PHP `check()` files and
  Python `unittest`. PHPStan level 9 must report no new errors, and Semgrep findings
  stay at the known false positives.

## Left to the builder

- Field types and storage for the list (an ArrayField of games, with the hosts and
  ranges as list fields), the API action names, and how the form edits multi-line
  lists.
- How the learned memory ties addresses to hosts, so behaviour 3 holds, and how each
  host's outcome is recorded for the Games list.
- The record of the keys the list has held, and where the add-new-games step runs.
- The exact shared front-end ranges and shared-hosting domains: sourced and checked
  the way `shared_cdn_ranges` is, and how the guards fit the refresh's 10-second
  budget.
- How `sync.php` reads the enabled games from the configuration instead of
  `gaming.json`'s `servers`. `gaming.json` stays the source of the defaults, and of
  everything listed under Unchanged.
- The order of the work and the tests.

## Decided with the maintainer

- Today's shipped list is the default; the parent can add, edit, delete and switch
  games on or off, and the list applies to every device denied gaming.
- Later releases keep the parent's list as it is and add only newly shipped games.
- Every entry is guarded at run time and on save.
- The Games list shows what each game is blocking, and why when it blocks nothing.
