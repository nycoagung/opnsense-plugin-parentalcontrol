{#
 # Parental Control - settings page
 #}

<script>
    $(document).ready(function () {
        $("#grid-devices").UIBootgrid({
            search: '/api/parentalcontrol/settings/searchDevice',
            get: '/api/parentalcontrol/settings/getDevice/',
            set: '/api/parentalcontrol/settings/setDevice/',
            add: '/api/parentalcontrol/settings/addDevice/',
            del: '/api/parentalcontrol/settings/delDevice/',
            toggle: '/api/parentalcontrol/settings/toggleDevice/',
            options: {
                formatters: {
                    commands: function (column, row) {
                        return '<button type="button" class="btn btn-xs btn-default command-edit bootgrid-tooltip" ' +
                               'data-row-id="' + row.uuid + '"><span class="fa fa-pencil fa-fw"></span></button> ' +
                               '<button type="button" class="btn btn-xs btn-default command-copy bootgrid-tooltip" ' +
                               'data-row-id="' + row.uuid + '"><span class="fa fa-clone fa-fw"></span></button> ' +
                               '<button type="button" class="btn btn-xs btn-default command-delete bootgrid-tooltip" ' +
                               'data-row-id="' + row.uuid + '"><span class="fa fa-trash-o fa-fw"></span></button>';
                    }
                }
            },
        });

        /*
         * Address suggestions from Dnsmasq. A native <datalist> is used rather
         * than a select widget so the field stays a plain text input: typing an
         * arbitrary address still works, and the model's own validation is
         * unchanged.
         *
         * Which value is suggested depends on how the device is known:
         *   - a static reservation suggests its IP, because the reservation
         *     already pins that MAC to that address permanently
         *   - a device seen only in a lease suggests its MAC, because its
         *     address is dynamic and would drift
         */
        var choicesLoaded = false;
        function loadAddressChoices() {
            if (choicesLoaded) { return; }
            choicesLoaded = true;
            var seen = {};
            var $list = $('#pc-address-list');
            if ($list.length === 0) {
                $list = $('<datalist id="pc-address-list"></datalist>').appendTo('body');
            }
            var add = function (value, label) {
                if (!value || seen[value]) { return; }
                seen[value] = true;
                $list.append($('<option>').attr('value', value).attr('label', label));
            };
            /* dnsmasq writes '*' for a client that sent no hostname */
            var nameOf = function (v, fallback) {
                return (v && v !== '*') ? v : fallback;
            };
            ajaxCall('/api/dnsmasq/settings/searchHost', {rowCount: 1000}, function (hosts) {
                var byIp = {};
                ((hosts || {}).rows || []).forEach(function (h) {
                    if (!h.ip) { return; }
                    byIp[h.ip] = true;
                    add(h.ip, nameOf(h.host, h.ip) + '  ·  reserved' + (h.hwaddr ? '  ·  ' + h.hwaddr : ''));
                });
                ajaxCall('/api/dnsmasq/leases/search', {rowCount: 1000}, function (leases) {
                    ((leases || {}).rows || []).forEach(function (l) {
                        if (!l.address || byIp[l.address]) { return; }
                        if (l.hwaddr) {
                            add(l.hwaddr, nameOf(l.hostname, l.address) + '  ·  ' + l.address + '  ·  dynamic, tracked by MAC');
                        } else {
                            add(l.address, nameOf(l.hostname, l.address) + '  ·  dynamic');
                        }
                    });
                });
            });
        }

        /* attach the list each time the dialog opens - the field is re-rendered */
        $('#DialogDevice').on('shown.bs.modal', function () {
            loadAddressChoices();
            var $addr = $('#device\\.address');
            $addr.attr('list', 'pc-address-list').attr('autocomplete', 'off');
            /* base_form has no native time type, so promote these to HTML5 time
               inputs after render. Their value format is HH:MM, which is exactly
               what the model already validates, so nothing else changes. */
            $('#device\\.allow_from, #device\\.allow_to').attr('type', 'time').attr('step', '60');
            $addr.off('change.pcfill').on('change.pcfill', function () {
                /* fill an empty name from the chosen entry, never overwrite one */
                var $name = $('#device\\.name');
                if ($name.val()) { return; }
                var opt = $('#pc-address-list option[value="' + $(this).val() + '"]').attr('label') || '';
                var label = opt.split('  ·  ')[0];
                if (label) { $name.val(label); }
            });
        });

        var data_get_map = {'frm_general': "/api/parentalcontrol/settings/get"};
        mapDataToFormUI(data_get_map).done(function () {
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
        });

        $("#saveAct").click(function () {
            saveFormToEndpoint("/api/parentalcontrol/settings/set", 'frm_general', function () {
                /* applying is the Apply button's job - it owns the standard
                   "changes have been applied" message and its placement */
                $("#change_message_base_form").slideDown(1000, function () {
                    setTimeout(function () { $("#change_message_base_form").slideUp(2000); }, 2000);
                });
            }, true);
        });

        // Resolved state comes from the same script the cron run uses, so the
        // page can never disagree with what is actually enforced.
        var loadStatus = function () {
            ajaxGet("/api/parentalcontrol/service/status", {}, function (data) {
                if (data === undefined || data.devices === undefined) { return; }
                var rows = data.devices.map(function (d) {
                    var badge = d.blocked
                        ? '<span class="label label-danger">{{ lang._("blocked") }}</span>'
                        : '<span class="label label-success">{{ lang._("allowed") }}</span>';
                    // a MAC only means something once resolved, so show what it
                    // actually matched rather than just the configured value
                    var addr = $('<div>').text(d.address).html();
                    if (d.is_mac) {
                        addr += d.resolved
                            ? '<br/><small class="text-muted">&rarr; ' + $('<div>').text(d.resolved).html() + '</small>'
                            : '<br/><small class="text-danger">{{ lang._("not resolvable right now") }}</small>';
                    }
                    return '<tr><td>' + $('<div>').text(d.name).html() + '</td>' +
                           '<td>' + addr + '</td>' +
                           '<td>' + $('<div>').text(d.reason).html() + '</td>' +
                           '<td>' + badge + '</td></tr>';
                }).join('');
                $("#status-body").html(rows);
                $("#status-alias").text(data.alias + ' (' + data.in_alias + ' ' + "{{ lang._('entries in pf') }}" + ')');
                var cron = {'enabled': '', 'disabled': "{{ lang._('schedule cron is disabled') }}",
                            'absent': "{{ lang._('schedule cron not installed') }}"}[data.cron] || '';
                $("#status-cron").html(cron ? ' &middot; <span class="text-danger">' + cron + '</span>' : '');
            });
        };
        loadStatus();
        $("#refreshStatus").click(function () { loadStatus(); });

        /* base_apply_button only emits the markup; without this the button has
           no label and does nothing */
        $("#reconfigureAct").SimpleActionButton({
            onAction: function () { loadStatus(); }
        });
    });
</script>

<ul class="nav nav-tabs" role="tablist" id="maintabs">
    <li class="active"><a data-toggle="tab" href="#devices">{{ lang._('Devices') }}</a></li>
    <li><a data-toggle="tab" href="#status">{{ lang._('Status') }}</a></li>
    <li><a data-toggle="tab" href="#settings">{{ lang._('Settings') }}</a></li>
</ul>

<div class="tab-content content-box">
    <div id="devices" class="tab-pane fade in active">
        <table id="grid-devices" class="table table-condensed table-hover table-striped"
               data-editDialog="DialogDevice" data-editAlert="change_message_base_form">
            <thead>
                <tr>
                    <th data-column-id="uuid" data-type="string" data-identifier="true" data-visible="false">{{ lang._('ID') }}</th>
                    <th data-column-id="enabled" data-width="6em" data-type="string" data-formatter="rowtoggle">{{ lang._('Enabled') }}</th>
                    <th data-column-id="name" data-type="string">{{ lang._('Name') }}</th>
                    <th data-column-id="address" data-type="string">{{ lang._('Address') }}</th>
                    <th data-column-id="mode" data-type="string">{{ lang._('Mode') }}</th>
                    <th data-column-id="allow_from" data-type="string" data-width="6em">{{ lang._('From') }}</th>
                    <th data-column-id="allow_to" data-type="string" data-width="6em">{{ lang._('Until') }}</th>
                    <th data-column-id="override" data-type="string">{{ lang._('Override') }}</th>
                    <th data-column-id="description" data-type="string">{{ lang._('Description') }}</th>
                    <th data-column-id="commands" data-width="7em" data-formatter="commands" data-sortable="false">{{ lang._('Commands') }}</th>
                </tr>
            </thead>
            <tbody></tbody>
            <tfoot>
                <tr>
                    <td></td>
                    <td colspan="9"><button data-action="add" type="button" class="btn btn-xs btn-default"><span class="fa fa-plus fa-fw"></span></button></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div id="status" class="tab-pane fade in">
        <div class="table-responsive">
            <table class="table table-condensed table-striped">
                <thead>
                    {# the summary row lives inside the table so it lines up with
                       the columns by construction, rather than by guessing padding #}
                    <tr>
                        <th colspan="3" style="border-bottom: none;">
                            {{ lang._('Alias') }}: <span id="status-alias">-</span>
                            <span id="status-cron" class="text-muted"></span>
                        </th>
                        <th style="border-bottom: none; text-align: right;">
                            <button id="refreshStatus" type="button" class="btn btn-xs btn-default">
                                <span class="fa fa-refresh fa-fw"></span> {{ lang._('Refresh') }}
                            </button>
                        </th>
                    </tr>
                    <tr>
                        <th>{{ lang._('Name') }}</th>
                        <th>{{ lang._('Address') }}</th>
                        <th>{{ lang._('Reason') }}</th>
                        <th>{{ lang._('State') }}</th>
                    </tr>
                </thead>
                <tbody id="status-body"></tbody>
            </table>
        </div>
    </div>

    <div id="settings" class="tab-pane fade in">
        {{ partial("layout_partials/base_form", ['fields': generalForm, 'id': 'frm_general']) }}
        <div class="col-md-12">
            <hr/>
            <button class="btn btn-primary" id="saveAct" type="button">
                <b>{{ lang._('Save') }}</b>
                <i id="saveAct_progress" class=""></i>
            </button>
        </div>
    </div>
</div>

{{ partial("layout_partials/base_apply_button", {'data_endpoint': '/api/parentalcontrol/service/reconfigure'}) }}

{{ partial("layout_partials/base_dialog",['fields': deviceForm, 'id':'DialogDevice', 'label':lang._('Edit device')]) }}
