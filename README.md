# os-parentalcontrol

Turn a device's internet access on or off, on a schedule or on demand.

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

## Schedules

A device is **always allowed**, **always blocked**, or **scheduled**. A schedule
is the window during which internet is *allowed*, plus the days it applies on —
a bedtime rule is "allowed 07:00–21:00", not "blocked 21:00–07:00". Expressing it
one way round removes the usual ambiguity. A window whose start is later than its
end crosses midnight.

**Override** is separate from the schedule and wins over it. The widget's switch
writes the override, so flicking a device off does not edit its hours; the clock
button clears the override and hands the device back to its schedule.

## Why existing connections get dropped

A device that becomes blocked keeps its established connections — an in-progress
stream runs to completion and the block looks broken. With *Drop existing
connections* enabled (the default) their states are dropped, so off means off.

## Nothing is installation-specific

The block rule has no interface set, so it covers every interface including ones
added later. "Not the internet" is RFC1918, which is the definition of private
address space rather than an assumption about any particular network. The alias
name is configurable.

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
of downloading again, so the bootstrap above costs exactly one fetch.

Then reload the GUI and open **Firewall → Parental Control**.

## The two configd actions

    configctl parentalcontrol sync      re-evaluate every device, sync the alias
    configctl parentalcontrol install   re-fetch the plugin from upstream

They are not interchangeable. `sync` applies device states using the code already
on the firewall; it is the one cron needs. `install` pulls new code and does not
touch device state.

## Keeping it installed

These files are not owned by a package, so a firmware upgrade can remove them.

- The **every-minute schedule job is created automatically** when you enable the
  plugin, and disabled again when you disable it. It is not optional — schedules
  are only re-evaluated when it runs, so without it the Status tab would show the
  right answer while nothing actually changed. It appears in System → Settings →
  Cron with origin `parentalcontrol`; the plugin owns it, so leave it alone.
- Add cron *Install/refresh Parental Control plugin from upstream* weekly
  yourself, to reinstate the files after an upgrade removes them.

The Status tab flags it in red if that job is ever missing or disabled while the
plugin is enabled, so a silently-inert install is visible rather than something
you discover when a bedtime does not happen.

The installer is safe to run from cron: it only restarts configd when the action
file actually changed, because restarting configd from a script configd launched
would kill that script mid-run.

## Uninstall

    /usr/local/opnsense/scripts/OPNsense/ParentalControl/uninstall.sh

Flushes the pf table, deletes the rule, deletes both aliases, removes the cron
job, then removes the files. Order matters: the table and rule go before the
aliases they reference, so nothing is left blocked by an object that no longer
has an owner. Everything is matched by description marker, so a renamed alias is
still found. Safe to run repeatedly.

Device settings stay in `config.xml`, so reinstalling restores them. Add
`--purge` to remove those as well.

The config-side cleanup is also available on its own as
`configctl parentalcontrol uninstall` — useful to disable enforcement while
leaving the plugin installed. The shell script is deliberately *not* a configd
action, because it deletes the action file and restarts configd, and a script
configd launched would be killed partway through that.

## Requirements

OPNsense 26.7 or later.

**IPv4 only, and this matters.** The block rule is `inet`, so on a dual-stack
firewall a blocked device keeps full internet over IPv6 — and will prefer it —
while every indicator here says "blocked". The Status tab detects active IPv6
and warns in red, but detection is not prevention: if you run IPv6, this plugin
does not do what it claims. Blocking IPv6 properly needs a second rule plus
`ndp`-based MAC resolution, which is not implemented.
