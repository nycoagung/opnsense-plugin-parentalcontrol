#!/bin/sh
# Remove the Parental Control plugin completely.
#
#   /usr/local/opnsense/scripts/OPNsense/ParentalControl/uninstall.sh
#
# Deliberately NOT a configd action: it deletes the action file and restarts
# configd, and a script configd launched would be killed partway through that.
# The config-side cleanup IS a configd action, invoked below.
#
# Device settings are left in config.xml, so reinstalling restores them. Pass
# --purge to remove those too.
set -e

MVC=/usr/local/opnsense/mvc/app
WWW=/usr/local/opnsense/www/js/widgets
SCRIPTS=/usr/local/opnsense/scripts/OPNsense/ParentalControl
ACTIONS=/usr/local/opnsense/service/conf/actions.d

echo "removing firewall objects and the cron job"
configctl parentalcontrol uninstall || echo "  (configd action unavailable - continuing)"

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

service configd restart >/dev/null 2>&1 || true
echo "done - reload the GUI; Firewall > Parental Control is gone"
