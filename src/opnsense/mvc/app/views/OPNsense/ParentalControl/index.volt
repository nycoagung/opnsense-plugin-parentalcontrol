{#
 # Parental Control - settings page
 #}

<script>
    $(document).ready(function () {
        var reconfigure = function (done) {
            ajaxCall("/api/parentalcontrol/service/reconfigure", {}, function () {
                if (done !== undefined) { done(); }
            });
        };

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
            onAction: function () { reconfigure(); }
        });

        var data_get_map = {'frm_general': "/api/parentalcontrol/settings/get"};
        mapDataToFormUI(data_get_map).done(function () {
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
        });

        $("#saveAct").click(function () {
            saveFormToEndpoint("/api/parentalcontrol/settings/set", 'frm_general', function () {
                reconfigure(function () {
                    $("#responseMsg").removeClass("hidden").html("{{ lang._('Applied.') }}");
                    loadStatus();
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
                    return '<tr><td>' + $('<div>').text(d.name).html() + '</td>' +
                           '<td>' + $('<div>').text(d.address).html() + '</td>' +
                           '<td>' + $('<div>').text(d.reason).html() + '</td>' +
                           '<td>' + badge + '</td></tr>';
                }).join('');
                $("#status-body").html(rows);
                $("#status-alias").text(data.alias + '  (' + data.in_alias + ' ' + "{{ lang._('entries in pf') }}" + ')');
            });
        };
        loadStatus();
        $("#refreshStatus").click(function () { loadStatus(); });
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
               data-editDialog="DialogDevice" data-editAlert="deviceChangeMessage">
            <thead>
                <tr>
                    <th data-column-id="uuid" data-type="string" data-identifier="true" data-visible="false">{{ lang._('ID') }}</th>
                    <th data-column-id="enabled" data-width="6em" data-type="string" data-formatter="rowtoggle">{{ lang._('Enabled') }}</th>
                    <th data-column-id="name" data-type="string">{{ lang._('Name') }}</th>
                    <th data-column-id="address" data-type="string">{{ lang._('Address') }}</th>
                    <th data-column-id="mode" data-type="string">{{ lang._('Mode') }}</th>
                    <th data-column-id="override" data-type="string">{{ lang._('Override') }}</th>
                    <th data-column-id="description" data-type="string">{{ lang._('Description') }}</th>
                    <th data-column-id="commands" data-width="7em" data-formatter="commands" data-sortable="false">{{ lang._('Commands') }}</th>
                </tr>
            </thead>
            <tbody></tbody>
            <tfoot>
                <tr>
                    <td></td>
                    <td colspan="7"><button data-action="add" type="button" class="btn btn-xs btn-default"><span class="fa fa-plus fa-fw"></span></button></td>
                </tr>
            </tfoot>
        </table>
        <div class="col-md-12">
            <div id="deviceChangeMessage" class="alert alert-info" style="display: none" role="alert">
                {{ lang._('After changing settings, please remember to apply them with the button below') }}
            </div>
        </div>
    </div>

    <div id="status" class="tab-pane fade in">
        <div class="content-box-main">
            <p><b>{{ lang._('Alias') }}:</b> <span id="status-alias">-</span>
               <button id="refreshStatus" type="button" class="btn btn-xs btn-default pull-right">
                   <span class="fa fa-refresh fa-fw"></span> {{ lang._('Refresh') }}
               </button></p>
            <table class="table table-condensed table-striped">
                <thead><tr>
                    <th>{{ lang._('Name') }}</th>
                    <th>{{ lang._('Address') }}</th>
                    <th>{{ lang._('Reason') }}</th>
                    <th>{{ lang._('State') }}</th>
                </tr></thead>
                <tbody id="status-body"></tbody>
            </table>
        </div>
    </div>

    <div id="settings" class="tab-pane fade in">
        {{ partial("layout_partials/base_form", ['fields': generalForm, 'id': 'frm_general']) }}
    </div>
</div>

<section class="page-content-main">
    <div class="content-box">
        <div class="col-md-12">
            <br/>
            <button class="btn btn-primary" id="saveAct" type="button">
                <b>{{ lang._('Save and apply') }}</b>
                <i id="saveAct_progress" class=""></i>
            </button>
            <br/><br/>
            <div id="responseMsg" class="alert alert-info hidden" role="alert"></div>
        </div>
    </div>
</section>

{{ partial("layout_partials/base_dialog",['fields': deviceForm, 'id':'DialogDevice', 'label':lang._('Edit device')]) }}
