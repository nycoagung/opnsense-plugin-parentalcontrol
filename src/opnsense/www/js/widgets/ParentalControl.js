/*
 * ParentalControl.js - OPNsense dashboard widget
 *
 * One row per configured device with an internet on/off switch.
 *
 * The switch writes the device's *override*, it does not edit the schedule.
 * Flicking it off blocks the device until you flick it back or press the
 * schedule button, which clears the override and returns the device to its
 * configured hours. Keeping "right now" and "normally" as separate concepts is
 * what stops a quick toggle silently destroying someone's schedule.
 *
 * Device state is never computed here. It comes from the same sync script the
 * cron run and the settings page use, so the widget cannot disagree with what
 * is actually enforced.
 */

export default class ParentalControl extends BaseWidget {

    constructor(config) {
        super(config);
        this.tickTimeout = 60;
        this.rows = [];
    }

    getGridOptions() { return { sizeToContent: 650 }; }

    getMarkup() {
        return $(`
        <div class="pc-wrap" style="position:relative;">
            <div class="pc-loading" style="display:none;position:absolute;top:0;left:0;right:0;bottom:0;
                 background:rgba(127,127,127,0.12);z-index:20;align-items:center;justify-content:center;">
                <i class="fa fa-spinner fa-spin fa-2x" style="opacity:0.7;"></i>
            </div>
            <table class="table table-condensed table-hover" style="margin-bottom:4px;table-layout:fixed;width:100%;">
                <thead><tr>
                    <th style="text-align:left;width:42%;">Device</th>
                    <th style="text-align:left;width:33%;">State</th>
                    <th style="text-align:right;width:25%;">Internet</th>
                </tr></thead>
                <tbody class="pc-body"></tbody>
            </table>
            <div class="pc-msg"><small class="text-muted"></small></div>
        </div>`);
    }

    _esc(s) { return $('<div>').text(s === null || s === undefined ? '' : String(s)).html(); }

    _busy(on) {
        this._n = Math.max(0, (this._n || 0) + (on ? 1 : -1));
        if (this._n > 0) {
            if (!this._t) { this._t = setTimeout(() => { this._t = null; $('.pc-loading').css('display', 'flex'); }, 150); }
        } else {
            clearTimeout(this._t); this._t = null; $('.pc-loading').css('display', 'none');
        }
    }

    _fitHeight() {
        try {
            const item = $('.pc-wrap').closest('.grid-stack-item')[0];
            if (!item) return;
            const gridEl = item.closest('.grid-stack');
            const grid = gridEl && gridEl.gridstack;
            if (grid && typeof grid.resizeToContent === 'function') grid.resizeToContent(item);
        } catch (e) { /* not on a gridstack dashboard */ }
    }

    async onMarkupRendered() {
        const self = this;
        $(document).off('.parentalcontrol');

        $(document).on('click.parentalcontrol', '.pc-toggle', async function () {
            const uuid = $(this).data('uuid');
            // the switch shows the CURRENT state, so clicking it asks for the opposite
            const want = $(this).attr('data-blocked') === '1' ? 'allow' : 'block';
            await self._setOverride(uuid, want);
        });
        $(document).on('click.parentalcontrol', '.pc-auto', async function () {
            await self._setOverride($(this).data('uuid'), 'none');
        });

        await this.refresh();
    }

    async _setOverride(uuid, value) {
        this._busy(true);
        try {
            await this.ajaxCall(`/api/parentalcontrol/settings/setOverride/${uuid}`,
                                JSON.stringify({ override: value }), 'POST');
            await this.refresh();
        } catch (e) {
            $('.pc-msg small').text('Could not apply the change');
        } finally { this._busy(false); }
    }

    async onWidgetTick() { await this.refresh(); }

    async refresh() {
        this._busy(true);
        try {
            const data = await this.ajaxCall('/api/parentalcontrol/service/status');
            if (!data || !Array.isArray(data.devices)) {
                $('.pc-body').html('<tr><td colspan="3" class="text-muted">' +
                    '<a href="/ui/parentalcontrol">Not configured yet</a></td></tr>');
                return;
            }
            this.rows = data.devices;
            this._render(data);
        } catch (e) {
            $('.pc-body').html('<tr><td colspan="3" class="text-danger">Unable to read status</td></tr>');
        } finally { this._busy(false); }
    }

    _render(data) {
        const clip = 'white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
        if (this.rows.length === 0) {
            $('.pc-body').html('<tr><td colspan="3" class="text-muted">' +
                '<a href="/ui/parentalcontrol">No devices configured</a></td></tr>');
        } else {
            $('.pc-body').html(this.rows.map((d) => {
                const on = !d.blocked;
                const overridden = d.override && d.override !== 'none';
                const btn = `<button type="button" class="btn btn-xs pc-toggle ${on ? 'btn-success' : 'btn-danger'}"
                             data-uuid="${this._esc(d.uuid)}" data-blocked="${d.blocked ? '1' : '0'}"
                             title="${on ? 'Block internet' : 'Allow internet'}">
                             <i class="fa fa-power-off fa-fw"></i> ${on ? 'On' : 'Off'}</button>`;
                const auto = overridden
                    ? ` <button type="button" class="btn btn-xs btn-default pc-auto"
                        data-uuid="${this._esc(d.uuid)}" title="Clear override, follow the schedule">
                        <i class="fa fa-clock-o fa-fw"></i></button>`
                    : '';
                return `<tr>
                    <td style="text-align:left;${clip}" title="${this._esc(d.address)}">
                        <strong>${this._esc(d.name)}</strong><br/>
                        <small class="text-muted">${this._esc(d.address)}</small></td>
                    <td style="text-align:left;${clip}" title="${this._esc(d.reason)}">
                        <small>${this._esc(d.reason)}</small></td>
                    <td style="text-align:right;white-space:nowrap;">${btn}${auto}</td></tr>`;
            }).join(''));
        }
        $('.pc-msg small').text(data.enabled
            ? `${data.in_alias} blocked via ${data.alias}`
            : 'Parental control is disabled - no device is being blocked');
        this._fitHeight();
    }

    onWidgetClose() { $(document).off('.parentalcontrol'); }
}
