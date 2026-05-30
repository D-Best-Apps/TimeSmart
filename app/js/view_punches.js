// view_punches.js — read-only timesheet view.
// Totals are computed server-side and rendered directly; here we only wire up
// the date-range pickers on the filter form.
document.addEventListener('DOMContentLoaded', function () {
    ['weekFrom', 'weekTo'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) {
            new Litepicker({ element: el, singleMode: true, format: 'MM/DD/YYYY' });
        }
    });
});
