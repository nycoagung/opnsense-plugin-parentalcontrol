#!/bin/sh
# Remove the Parental Control plugin completely.
#
#   /usr/local/opnsense/scripts/OPNsense/ParentalControl/uninstall.sh
#
# Deliberately NOT a configd action: it deletes the action file and restarts
# configd, and a script configd launched would be killed partway through that.
# The config-side cleanup is also a configd action, but it is run directly
# below: configctl would report a refusal as "Execute error", with no exit
# status to stop on.
#
# Device settings are left in config.xml, so reinstalling restores them. Pass
# --purge to remove those too.
set -e

MVC=/usr/local/opnsense/mvc/app
WWW=/usr/local/opnsense/www/js/widgets
SCRIPTS=/usr/local/opnsense/scripts/OPNsense/ParentalControl
ACTIONS=/usr/local/opnsense/service/conf/actions.d
DATA=/var/db/parentalcontrol            # DATA_DIR in lib/markers.php

echo "removing firewall objects and the cron job"
# It says why when it stops; the files stay, so it can be run again.
"$SCRIPTS/uninstall.php" || { echo "stopped: no file was removed" >&2; exit 1; }

echo "removing files"
rm -f "$WWW/ParentalControl.js" "$WWW/Metadata/ParentalControl.xml"
rm -rf "$MVC/models/OPNsense/ParentalControl" \
       "$MVC/controllers/OPNsense/ParentalControl" \
       "$MVC/views/OPNsense/ParentalControl"
rm -f "$ACTIONS/actions_parentalcontrol.conf"

if [ "$1" = "--purge" ]; then
    echo "purging saved device settings"
    php -r 'require_once("config.inc");
            $c = OPNsense\Core\Config::getInstance();
            $o = $c->object();
            if (isset($o->OPNsense->ParentalControl)) {
                unset($o->OPNsense->ParentalControl);
                $c->save();
                echo "  device settings removed\n";
            } else {
                echo "  no saved settings found\n";
            }' || echo "  (could not purge settings)"
fi

# rm unlinks: this script keeps reading from its own open descriptor, so
# removing the directory it lives in mid-run is safe - unlike overwriting it.
rm -rf "$SCRIPTS"
# the learned server list and the sync lock, once uninstall.php is done with it
rm -rf "$DATA"

service configd restart >/dev/null 2>&1 || true
echo "done - reload the GUI; Firewall > Parental Control is gone"
