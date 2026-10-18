<?php

/**
 * Names, description markers and paths shared by sync.php and uninstall.php.
 * Constants only, so any script or test can load this anywhere PHP runs.
 */

/* The plugin finds its own alias and rule by these description prefixes rather
   than by name. Names are user-editable, so matching on them means a rename
   silently orphans the old objects - leaving whatever was blocked blocked
   forever, with nothing in the UI explaining why. Prefixes are used so an
   existing install whose rule description still embeds the old alias name is
   still recognised. */
const MARK_BLOCK = 'Parental Control: devices currently denied internet';
const MARK_LOCAL = 'Parental Control: local networks';
const MARK_RULE  = 'Parental Control: block internet';

/* The messaging block's objects, found the same way. */
const MARK_MSG_DEVICES = 'Parental Control: devices currently denied messaging';
const MARK_MSG_SERVERS = 'Parental Control: messaging servers';
const MARK_MSG_RULE    = 'Parental Control: block messaging';
const MARK_NOTIFY_RULE = 'Parental Control: block notification ports';

/* The cron job's description. Not a marker: the job is found by its origin and
   command. It lives here so that no 'Parental Control:' string is left in the
   scripts themselves. */
const CRON_DESCRIPTION = 'Parental Control: evaluate device schedules';

/* Unlike the whole-internet alias, whose name is a setting, these are fixed. */
const MSG_DEVICES_ALIAS = 'NoMessaging';
const MSG_SERVERS_ALIAS = 'Messaging_Servers';

/* Google reserves these TCP ports for its push-notification service, so one
   rule blocks them to any destination. The range is built from the two bounds
   so the three cannot drift apart. */
const NOTIFY_PORT_LO = 5228;
const NOTIFY_PORT_HI = 5230;
const NOTIFY_PORTS   = NOTIFY_PORT_LO . '-' . NOTIFY_PORT_HI;

/* The plugin's own state (the learned server list), kept out of config.xml. */
const DATA_DIR = '/var/db/parentalcontrol';

/* Left by a successful `uninstall.php --final` (uninstall.sh's run) until the
   next install removes it. While it exists a sync changes nothing, so a sync that
   waited for uninstall's lock cannot put back what uninstall removed. install.sh
   names the same path. */
const UNINSTALL_MARKER = DATA_DIR . '/uninstalling';
