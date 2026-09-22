# os-parentalcontrol

Turn a device's internet access on or off, on a schedule or on demand.

Adds **Firewall → Parental Control** and a dashboard widget with per-device
switches. Blocked devices lose *internet only* — printers, media players, NAS
and local dashboards keep working.

## How it enforces

One externally-managed alias holds the addresses currently denied internet, and
one floating rule blocks that alias to any destination outside private address
space. Toggling a device is a pf table update, so it takes effect immediately
with no ruleset reload.

Per-device rules were rejected deliberately: they do not scale, they apply
slowly, and arbitrary per-device schedules would need one rule per distinct
schedule. One alias and one rule stays comprehensible at any number of devices.

Both aliases and the rule are created automatically on first apply and are
idempotent. The rule is identified by its description, so it is never duplicated
and your existing rules are never touched. An empty alias means the rule matches
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

    fetch -o - https://raw.githubusercontent.com/nycoagung/opnsense-plugin-parentalcontrol/main/install.sh | sh

Files are pulled from the GitHub API and verified against the git blob SHA the
API reports; raw.githubusercontent is CDN-cached and can silently serve stale
content while reporting success.

Then reload the GUI and open **Firewall → Parental Control**.

## Keeping it installed

These files are not owned by a package, so a firmware upgrade can remove them.
Add a cron job running *Apply parental control device states* to reinstate them,
and to re-evaluate schedules — **run it every minute**, since schedule changes
only take effect when it runs.

## Requirements

OPNsense 26.7 or later. IPv4 only.
