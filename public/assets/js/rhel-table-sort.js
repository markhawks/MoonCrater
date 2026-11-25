(() => {
    const collator = new Intl.Collator('en', {numeric: true, sensitivity: 'base'});
    const missing = /^(?:N\/[AD]|NULL)?$/i;
    const cellValue = cell => cell.textContent.trim().replace(/^(?:⚙️?|⚠️?|📍|✕)\s*/, '');

    function checkinTimestamp(value) {
        const european = value.match(/^(\d{2})\/(\d{2})\/(\d{4})(.*)$/);
        if (european) {
            const [, day, month, year, time] = european;
            value = year + '-' + month + '-' + day + time;
        }
        const timestamp = Date.parse(value);
        return Number.isFinite(timestamp) ? timestamp : null;
    }

    document.querySelectorAll('table[data-rhel-sortable]').forEach(table => {
        const headers = Array.from(table.tHead.rows[0].cells).slice(1);
        const body = table.tBodies[0];
        headers.forEach(header => {
            const label = header.textContent.trim();
            const button = document.createElement('button');
            button.type = 'button';
            button.style.cssText = 'background:none;border:0;color:inherit;font:inherit;text-align:left;padding:0;cursor:pointer';
            const arrow = document.createElement('span');
            arrow.setAttribute('aria-hidden', 'true');
            arrow.textContent = '↕';
            button.append(label + ' ', arrow);
            header.replaceChildren(button);
            header.scope = 'col';

            button.addEventListener('click', () => {
                const ascending = header.getAttribute('aria-sort') !== 'ascending';
                const index = header.cellIndex;
                const rows = Array.from(body.rows).filter(row => row.cells.length === table.tHead.rows[0].cells.length);
                rows.sort((left, right) => {
                    let a = cellValue(left.cells[index]);
                    let b = cellValue(right.cells[index]);
                    if (label === 'Last Check-in') {
                        a = missing.test(a) ? null : checkinTimestamp(a);
                        b = missing.test(b) ? null : checkinTimestamp(b);
                    } else {
                        a = missing.test(a) ? null : a;
                        b = missing.test(b) ? null : b;
                    }
                    if (a === null || b === null) return Number(a === null) - Number(b === null);
                    const order = typeof a === 'number' ? a - b : collator.compare(a, b);
                    return ascending ? order : -order;
                });
                rows.forEach((row, position) => {
                    row.cells[0].textContent = String(position + 1);
                    body.appendChild(row);
                });
                headers.forEach(other => {
                    other.removeAttribute('aria-sort');
                    other.querySelector('span').textContent = '↕';
                });
                header.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');
                arrow.textContent = ascending ? '↑' : '↓';
            });
        });
    });
})();
