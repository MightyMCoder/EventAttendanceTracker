/** Stage configuration-specific permanent excuses through an Admidio-style modal. */
window.initEventAttendancePermanentExcuses = function (config) {
    const form = document.getElementById('adm_event_attendance_tracker_form');
    const payload = document.getElementById('tracker_permanent_excuses');
    const editor = document.getElementById('adm_event_attendance_excuse_editor');
    const editorForm = document.getElementById('adm_event_attendance_excuse_editor_form');
    const modal = bootstrap.Modal.getOrCreateInstance(editor);
    const addButton = document.getElementById('adm_event_attendance_add_excuse');
    const user = document.getElementById('excuse_user');
    const roleSelection = document.getElementById('tracker_roles');
    const people = Array.from(user.options, option => option.cloneNode(true));
    const from = document.getElementById('excuse_from');
    const until = document.getElementById('excuse_until');
    const id = document.getElementById('excuse_id');
    const table = $('#adm_event_attendance_excuses_table').DataTable();
    const template = document.getElementById('adm_event_attendance_excuse_row');
    let rules = new Map();
    let nextId = -1;
    let selectReady = false;

    function sync() {
        payload.value = JSON.stringify(Array.from(rules.values(), rule => ({
            id: Math.max(0, Number(rule.eax_id)), user: Number(rule.eax_usr_id),
            from: rule.eax_date_from, until: rule.eax_date_until || null
        })));
    }

    function displayDate(value) {
        if (!value) {
            return null;
        }
        const [year, month, day] = value.split('-');
        const parts = { Y: year, y: year.slice(-2), m: month, n: String(Number(month)), d: day, j: String(Number(day)) };
        return config.dateFormat.replace(/[Yymndj]/g, token => parts[token]);
    }

    function resetEditor() {
        if (selectReady) {
            $(user).select2('close');
        }
        $(editor).find(':input').prop('disabled', true);
        addButton.disabled = false;
        id.value = '0';
    }

    function closeEditor() {
        if (selectReady) {
            $(user).select2('close');
        }
        modal.hide();
    }

    function render(rows) {
        rules = new Map(rows.map(rule => [Number(rule.eax_id), rule]));
        table.clear();
        rows.forEach(function (rule) {
            const row = template.content.firstElementChild.cloneNode(true);
            row.querySelector('.excuse-name').textContent = rule.name;
            const fromCell = row.querySelector('.excuse-from');
            fromCell.textContent = rule.fromDisplay;
            const untilCell = row.querySelector('.excuse-until');
            untilCell.textContent = rule.untilDisplay || config.unlimited;
            row.querySelectorAll('[href], [data-href], [id], [aria-labelledby]').forEach(function (element) {
                ['href', 'data-href', 'id', 'aria-labelledby'].forEach(function (attribute) {
                    if (element.hasAttribute(attribute)) {
                        element.setAttribute(attribute, element.getAttribute(attribute).replaceAll('ROWID', Number(rule.eax_id)));
                    }
                });
            });
            table.row.add(row);
        });
        table.draw(false);
    }

    function edit(rule) {
        if (config.memberRoles && roleSelection) {
            const roles = new Set(Array.from(roleSelection.selectedOptions, option => Number(option.value)));
            $(user).empty();
            people.forEach(function (option) {
                const userId = Number(option.value);
                if (!userId || (rule && userId === Number(rule.eax_usr_id))
                    || (config.memberRoles[userId] || []).some(role => roles.has(Number(role)))) {
                    user.add(option.cloneNode(true));
                }
            });
        }
        id.value = rule ? rule.eax_id : '0';
        from.value = rule ? rule.eax_date_from : config.today;
        until.value = rule ? rule.eax_date_until || '' : '';
        until.min = from.value;
        $(editor).find(':input').prop('disabled', false);
        $(user).val(rule ? rule.eax_usr_id : '').trigger('change');
        addButton.disabled = true;
        modal.show();
    }

    editor.addEventListener('shown.bs.modal', function () {
        if (!selectReady) {
            // Recreate Admidio's initial instance after its hidden container is visible.
            if ($(user).data('select2')) {
                $(user).select2('destroy');
            }
            $(user).select2({
                theme: 'bootstrap-5', width: '100%', language: config.language,
                dropdownParent: $(editor)
            });
            selectReady = true;
        }
        if (Number(id.value) !== 0) {
            from.focus();
        } else {
            $(user).select2('open');
        }
    });
    editor.addEventListener('hidden.bs.modal', resetEditor);

    function applyEditor() {
        for (const field of [user, from, until]) {
            if (!field.reportValidity()) {
                return false;
            }
        }
        const ruleId = Number(id.value) || nextId--;
        rules.set(ruleId, {
            eax_id: ruleId, eax_usr_id: Number(user.value),
            name: user.selectedOptions[0].textContent,
            eax_date_from: from.value, eax_date_until: until.value || null,
            fromDisplay: displayDate(from.value), untilDisplay: displayDate(until.value)
        });
        render(Array.from(rules.values()));
        sync();
        closeEditor();
        return true;
    }

    window.eventAttendanceEditPermanentExcuse = function (ruleId) {
        const rule = rules.get(Number(ruleId));
        if (rule) {
            edit(rule);
        }
    };
    window.eventAttendanceDeletePermanentExcuse = function (ruleId) {
        if (rules.delete(Number(ruleId))) {
            if (Number(id.value) === Number(ruleId)) {
                closeEditor();
            }
            render(Array.from(rules.values()));
            sync();
            $('#adm_modal_messagebox, #adm_modal').modal('hide');
        }
    };
    addButton.addEventListener('click', function () { edit(null); });
    editorForm.addEventListener('submit', function (event) {
        event.preventDefault();
        applyEditor();
    });
    from.addEventListener('change', function () { until.min = from.value; });
    form.addEventListener('submit', function () {
        sync();
    }, true);
    resetEditor();
    render(config.rules);
};
