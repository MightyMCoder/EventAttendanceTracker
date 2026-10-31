/**
 * Requires jsdom and the surrounding Admidio installation.
 * Run: NODE_PATH=/path/to/jsdom/node_modules node tests/permanent_excuses_table.cjs
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const plugin = path.resolve(__dirname, '..');
const core = path.resolve(plugin, '../..');

function createPage(rows, waitForInit, memberRoles) {
    const dom = new JSDOM(`<!doctype html><html><body>
        <form id="adm_event_attendance_tracker_form">
            <input id="tracker_permanent_excuses" value="[]">
            <table id="adm_event_attendance_excuses_table">
                <thead><tr><th>Person</th><th>From</th><th>Until</th><th>Actions</th></tr></thead><tbody></tbody>
            </table>
            <button id="adm_event_attendance_add_excuse" type="button">Add</button>
        </form>
        <div id="adm_event_attendance_excuse_editor"><form id="adm_event_attendance_excuse_editor_form">
            <input id="excuse_id" value="0">
            <select id="excuse_user" required><option value=""></option><option value="10">Test Person</option></select>
            <input id="excuse_from" type="date" required><input id="excuse_until" type="date">
        </form></div>
        <template id="adm_event_attendance_excuse_row"><tr>
            <td class="excuse-name"></td><td class="excuse-from"></td><td class="excuse-until"></td><td></td>
        </tr></template>
    </body></html>`, { runScripts: 'outside-only', url: 'https://example.test/' });
    const window = dom.window;
    const warnings = [];
    window.alert = message => warnings.push(message);
    window.eval(fs.readFileSync(path.join(core, 'libs/jquery/jquery.js'), 'utf8'));
    window.eval(fs.readFileSync(path.join(core, 'libs/datatables/datatables.js'), 'utf8'));
    const $ = window.jQuery;
    let languageRequest;
    $.ajax = options => { languageRequest = options; };
    $.fn.modal = function () { return this; };
    $.fn.select2 = function (argument) {
        if (typeof argument === 'object') { this.data('select2', {}); }
        return this;
    };
    const editor = window.document.getElementById('adm_event_attendance_excuse_editor');
    window.bootstrap = { Modal: { getOrCreateInstance: () => ({
        show: () => editor.dispatchEvent(new window.Event('shown.bs.modal')),
        hide: () => editor.dispatchEvent(new window.Event('hidden.bs.modal'))
    }) } };
    window.eval(fs.readFileSync(path.join(plugin, 'js/permanent-excuses.js'), 'utf8'));
    const config = { rules: rows, today: '2030-01-01', dateFormat: 'Y-m-d', language: 'en', unlimited: 'No end date' };
    if (memberRoles) {
        // A new configuration has no stored roles yet; candidate people follow the draft selection.
        const select = window.document.createElement('select');
        select.id = 'tracker_roles';
        select.multiple = true;
        select.innerHTML = '<option value="1">First role</option><option value="2">Second role</option>';
        window.document.getElementById('adm_event_attendance_tracker_form').appendChild(select);
        config.memberRoles = memberRoles;
    }
    const tableElement = $('#adm_event_attendance_excuses_table');
    if (waitForInit) {
        tableElement.one('init.dt', () => window.initEventAttendancePermanentExcuses(config));
    }
    const table = tableElement.DataTable({ language: { url: '/language.json' } });
    if (!waitForInit) {
        window.initEventAttendancePermanentExcuses(config);
    }
    assert.ok(languageRequest, 'Language loading must still be pending.');
    languageRequest.success({});
    return { window, table, warnings, dom };
}

const broken = createPage([], false);
assert.ok(broken.warnings.some(message => message.includes("Requested unknown parameter '1'")), 'Original initialization race must be reproduced.');
broken.dom.window.close();

const draft = createPage([], true, { 10: [1] });
const draftDocument = draft.window.document;
const addDraft = draftDocument.getElementById('adm_event_attendance_add_excuse');
addDraft.click();
assert.equal(draftDocument.querySelector('#excuse_user option[value="10"]'), null, 'Do not offer people before their role is selected.');
draftDocument.getElementById('adm_event_attendance_excuse_editor').dispatchEvent(new draft.window.Event('hidden.bs.modal'));
draftDocument.querySelector('#tracker_roles option[value="1"]').selected = true;
addDraft.click();
assert.ok(draftDocument.querySelector('#excuse_user option[value="10"]'), 'A person from a newly selected role must be available without saving.');
draftDocument.getElementById('excuse_user').value = '10';
draftDocument.getElementById('adm_event_attendance_excuse_editor_form').dispatchEvent(new draft.window.Event('submit', { cancelable: true }));
assert.equal(draft.table.rows().count(), 1, 'Stage the first permanent excuse on a new configuration.');
assert.equal(JSON.parse(draftDocument.getElementById('tracker_permanent_excuses').value)[0].user, 10, 'Include the draft in the main configuration payload.');
draft.dom.window.close();

const empty = createPage([], true);
assert.deepEqual(empty.warnings, [], 'Empty table must initialize without warnings.');
assert.equal(empty.table.rows().count(), 0, 'Placeholder must not become a data row.');
empty.window.document.getElementById('adm_event_attendance_add_excuse').click();
empty.window.document.getElementById('excuse_user').value = '10';
empty.window.document.getElementById('adm_event_attendance_excuse_editor_form').dispatchEvent(new empty.window.Event('submit', { cancelable: true }));
assert.equal(empty.table.rows().count(), 1, 'Adding the first draft row must work.');
assert.equal(JSON.parse(empty.window.document.getElementById('tracker_permanent_excuses').value).length, 1);
empty.window.eventAttendanceDeletePermanentExcuse(-1);
assert.equal(empty.table.rows().count(), 0, 'Deleting the last draft row must restore an empty table.');
assert.deepEqual(empty.warnings, []);
empty.dom.window.close();

const existing = createPage([{
    eax_id: 1, eax_usr_id: 10, name: 'Test Person', eax_date_from: '2030-01-01', eax_date_until: null,
    fromDisplay: '2030-01-01', untilDisplay: null
}], true);
assert.equal(existing.table.rows().count(), 1, 'Initial rows must not be duplicated.');
assert.deepEqual(existing.warnings, []);
existing.dom.window.close();

// Exercise the actual inline filter handlers from the HTTP entry point while
// their language request is still pending, then after initialization completes.
const entryPoint = fs.readFileSync(path.join(plugin, 'modules/index.php'), 'utf8');
for (const fixture of [
    { table: 'adm_event_attendance_trackers_table', filter: 'tracker_active_filter', attribute: 'data-status', selected: 'active', other: 'inactive', end: '\n\n                window.setEventAttendanceTrackerStatus' },
    { table: 'adm_event_attendance_dashboard_table', filter: 'dashboard_tracker_filter', attribute: 'data-tracker-uuid', selected: 'tracker1', other: 'tracker2', end: "\n            ' . ($attendanceLockEnabled" }
]) {
    const dom = new JSDOM(`<!doctype html><html><body>
        <select id="${fixture.filter}"><option value="all">All</option><option value="${fixture.selected}">Selected</option></select>
        <table id="${fixture.table}"><thead><tr><th>Name</th><th>Source</th><th>Date</th><th>Description</th><th>Actions</th></tr></thead><tbody>
            <tr ${fixture.attribute}="${fixture.selected}"><td>First</td><td>Calendar</td><td>2030-01-01</td><td>Description</td><td>Edit</td></tr>
            <tr ${fixture.attribute}="${fixture.other}"><td>Second</td><td>Calendar</td><td>2030-01-02</td><td>Description</td><td>Edit</td></tr>
        </tbody></table>
    </body></html>`, { runScripts: 'outside-only', url: 'https://example.test/' });
    const window = dom.window;
    const warnings = [];
    window.alert = message => warnings.push(message);
    window.eval(fs.readFileSync(path.join(core, 'libs/jquery/jquery.js'), 'utf8'));
    window.eval(fs.readFileSync(path.join(core, 'libs/datatables/datatables.js'), 'utf8'));
    const $ = window.jQuery;
    let languageRequest;
    $.ajax = options => { languageRequest = options; };
    const start = entryPoint.indexOf(`$("#${fixture.table}").one("init.dt"`);
    const end = entryPoint.indexOf(fixture.end, start);
    assert.ok(start >= 0 && end > start, 'Production filter initialization handler must exist.');
    window.eval(entryPoint.slice(start, end));
    const table = $(`#${fixture.table}`).DataTable({ language: { url: '/language.json' }, columnDefs: [{ targets: 4, orderable: false }] });
    $(`#${fixture.filter}`).val(fixture.selected).trigger('change');
    assert.equal(window.document.querySelectorAll(`#${fixture.table} tbody tr`).length, 2, 'An early filter change must not redraw uninitialized data.');
    languageRequest.success({});
    assert.equal(table.rows().count(), 2, 'Initial HTML rows must remain intact.');
    assert.equal(table.rows({ search: 'applied' }).count(), 1, 'Selection made during initialization must be applied.');
    $(`#${fixture.filter}`).val('all').trigger('change');
    assert.equal(table.rows({ search: 'applied' }).count(), 2, 'Filter must keep working after initialization.');
    assert.deepEqual(warnings, []);
    dom.window.close();
}
console.log('DataTables asynchronous initialization checks passed.');
