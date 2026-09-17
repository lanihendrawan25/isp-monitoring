document.addEventListener('DOMContentLoaded', function () {
    var addBtn = document.getElementById('btnAddRow');
    var syncBtn = document.getElementById('btnSyncDT');
    var tbody = document.getElementById('affectBody');

    function renumber() {
        tbody.querySelectorAll('.affect-row').forEach(function (r, i) {
            var no = r.querySelector('.row-no');
            if (no) no.textContent = (i + 1);
        });
    }

    function resetRow(row) {
        row.querySelectorAll('input, select').forEach(function (el) {
            if (el.classList.contains('f-mode')) {
                el.value = 'ping';
            } else {
                el.value = '';
            }
        });
    }

    function bindRow(row) {
        var del = row.querySelector('.btn-del-row');
        if (del) {
            del.addEventListener('click', function () {
                var rows = tbody.querySelectorAll('.affect-row');
                if (rows.length <= 1) {
                    resetRow(row);
                } else {
                    row.remove();
                    renumber();
                }
            });
        }
    }

    if (addBtn && tbody) {
        tbody.querySelectorAll('.affect-row').forEach(bindRow);

        addBtn.addEventListener('click', function () {
            var first = tbody.querySelector('.affect-row');
            var clone = first.cloneNode(true);
            clone.querySelectorAll('input, select').forEach(function (el) { el.value = ''; });
            var modeSel = clone.querySelector('.f-mode');
            if (modeSel) modeSel.value = 'ping';
            bindRow(clone);
            tbody.appendChild(clone);
            renumber();
        });
    }

    if (syncBtn && tbody) {
        syncBtn.addEventListener('click', function () {
            var first = tbody.querySelector('input[type=datetime-local]');
            var val = first ? first.value : '';
            tbody.querySelectorAll('input[type=datetime-local]').forEach(function (inp) { inp.value = val; });
        });
    }
});