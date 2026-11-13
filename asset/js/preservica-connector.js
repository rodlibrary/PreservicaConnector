(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('preservica-import-form');
        if (!form) {
            return;
        }
        var selectAll = document.getElementById('preservica-select-all');
        var button = document.getElementById('preservica-import-button');
        var count = document.getElementById('preservica-selected-count');
        var boxes = Array.prototype.slice.call(
            document.querySelectorAll('input[name="refs[]"]:not([disabled])')
        );

        function update() {
            var selected = boxes.filter(function (box) { return box.checked; }).length;
            button.disabled = selected === 0;
            count.textContent = selected ? count.dataset.template.replace('%s', selected) : '';
            selectAll.checked = boxes.length > 0 && selected === boxes.length;
            selectAll.indeterminate = selected > 0 && selected < boxes.length;
        }

        selectAll.disabled = boxes.length === 0;
        selectAll.addEventListener('change', function () {
            boxes.forEach(function (box) { box.checked = selectAll.checked; });
            update();
        });
        boxes.forEach(function (box) { box.addEventListener('change', update); });
        update();
    });
})();
