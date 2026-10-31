/** Requires jsdom and Admidio. Run with: php tests/form_feedback.php fixtures | node tests/form_feedback.cjs */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const plugin = path.resolve(__dirname, '..');
const core = path.resolve(plugin, '../..');
const fixtures = JSON.parse(fs.readFileSync(0, 'utf8'));
const dom = new JSDOM('<form id="adm_event_attendance_tracker_form" action="/save_tracker"><input name="value" value="unsaved"><div class="form-alert" style="display:none"></div><button id="adm_button_save_tracker" type="submit"><i class="bi bi-check-lg"></i>Save</button></form>' + '<form id="adm_event_attendance_form" action="/save_attendance"><input name="status" value="present"><div class="form-alert" style="display:none"></div><button id="adm_button_save_attendance" type="submit"><i class="bi bi-check-lg"></i>Save</button></form>', { url: 'https://example.test/', runScripts: 'outside-only' });
const { window } = dom;
window.eval(fs.readFileSync(path.join(core, 'libs/jquery/jquery.min.js'), 'utf8'));
const $ = window.jQuery;
$.fx.off = true;
const functions = fs.readFileSync(path.join(core, 'system/js/common_functions.js'), 'utf8');
window.eval(functions.slice(functions.indexOf('function formSubmit(event)'), functions.indexOf('\n/**', functions.indexOf('function formSubmit(event)'))));
let request;
let redirect;
let timers = [];
$.post = options => { request = options; };
window.setTimeout = (callback, delay) => { timers.push({ callback, delay }); };
window.redirectToURL = url => { redirect = url; };

for (const form of window.document.querySelectorAll('form')) {
    const button = $(form).find('button[type=submit]');
    const iconClass = button.find('i').attr('class');
    const otherButtons = $('button[type=submit]').not(button);
    timers = [];
    redirect = undefined;
    window.formSubmit.call(form, { preventDefault() {} });
    assert.equal(button.prop('disabled'), true, 'Only the submitted button must be disabled.');
    assert.ok(otherButtons.toArray().every(button => !button.disabled));
    assert.ok(request.data instanceof window.FormData, 'Admidio must continue submitting the full form.');
    request.success(JSON.stringify({ status: 'error', message: 'Validation failed.' }));
    assert.equal(button.prop('disabled'), false, 'Validation errors must allow retrying.');
    assert.equal(button.find('i').attr('class'), iconClass);
    assert.ok($(form).find('.form-alert').hasClass('alert-danger'));
    assert.match($(form).find('.form-alert').text(), /Validation failed/);
    assert.equal($(form).find('input').val(), form.id === 'adm_event_attendance_form' ? 'present' : 'unsaved', 'Errors must preserve input.');
    assert.equal(redirect, undefined);

    window.formSubmit.call(form, { preventDefault() {} });
    request.success(fixtures.saved);
    assert.ok($(form).find('.form-alert').hasClass('alert-success'));
    assert.match($(form).find('.form-alert').text(), /Data has been saved/);
    assert.equal(redirect, undefined, 'The success message must appear before returning to the caller.');
    const redirects = timers.filter(timer => timer.delay === 2500);
    assert.equal(redirects.length, 1, 'Use Admidio’s standard feedback delay.');
    redirects[0].callback();
    assert.equal(redirect, '/configurations');
    button.prop('disabled', false);
}

async function testHeartbeat() {
    const pending = [];
    const alerts = [];
    let tick;
    let stopped = false;
    window.fetch = () => new Promise(resolve => pending.push(resolve));
    window.setInterval = callback => { tick = callback; return 1; };
    window.clearInterval = () => { stopped = true; };
    window.alert = message => alerts.push(message);
    const form = window.document.getElementById('adm_event_attendance_form');
    $(form).append('<input id="attendance_lock_token" value="test-token">');
    const script = fixtures.attendanceScripts.find(script => script.includes('let heartbeatTimer;'));
    assert.ok(script, 'Test the actual generated attendance-lock handler.');
    window.eval(script);
    assert.equal(pending.length, 1, 'Start the heartbeat immediately.');
    const submit = () => form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
    submit();
    tick();
    assert.equal(pending.length, 1, 'Do not refresh the lock while saving.');
    pending.shift()({ json: async () => ({ status: 'locked' }) });
    await new Promise(setImmediate);
    assert.deepEqual(alerts, [], 'A pending heartbeat must not interrupt saving.');

    $(window.document).trigger('ajaxComplete', [{ responseText: '{"status":"error"}' }, { url: '/save_attendance' }]);
    assert.equal(pending.length, 1, 'Resume lock validation after a failed save.');
    pending.shift()({ json: async () => ({ status: 'success' }) });
    await new Promise(setImmediate);
    submit();
    $(window.document).trigger('ajaxComplete', [{ responseText: fixtures.saved }, { url: '/save_attendance' }]);
    assert.equal(stopped, true, 'Stop the heartbeat after saving releases the lock.');
    tick();
    assert.equal(pending.length, 0, 'Do not report an expired lock during success feedback.');
    assert.deepEqual(alerts, []);
}

testHeartbeat().then(() => {
    dom.window.close();
    console.log('Native form feedback and save/heartbeat coordination passed.');
}).catch(error => {
    dom.window.close();
    console.error(error);
    process.exitCode = 1;
});
