/* HamLog — frontend utilities */

// Auto-fill UTC date and time on log form
function fillUTCNow() {
    const now = new Date();
    const pad = n => String(n).padStart(2,'0');
    const date = now.getUTCFullYear() + '-' + pad(now.getUTCMonth()+1) + '-' + pad(now.getUTCDate());
    const time = pad(now.getUTCHours()) + ':' + pad(now.getUTCMinutes());
    const df = document.getElementById('date_on');
    const tf = document.getElementById('time_on');
    if (df && !df.value) df.value = date;
    if (tf) tf.value = time;
}

// Derive band from frequency
function freqToBand(freq) {
    const bands = [
        ['160m', 1.8,   2.0],
        ['80m',  3.5,   4.0],
        ['60m',  5.3,   5.4],
        ['40m',  7.0,   7.3],
        ['30m',  10.1,  10.15],
        ['20m',  14.0,  14.35],
        ['17m',  18.068,18.168],
        ['15m',  21.0,  21.45],
        ['12m',  24.89, 24.99],
        ['10m',  28.0,  29.7],
        ['6m',   50.0,  54.0],
        ['4m',   70.0,  70.5],
        ['2m',   144.0, 148.0],
        ['1.25m',222.0, 225.0],
        ['70cm', 420.0, 450.0],
        ['33cm', 902.0, 928.0],
        ['23cm', 1240.0,1300.0],
    ];
    for (const [b, lo, hi] of bands) {
        if (freq >= lo && freq <= hi) return b;
    }
    return '';
}

document.addEventListener('DOMContentLoaded', function() {
    // Auto-fill time on log page
    if (document.getElementById('date_on')) {
        fillUTCNow();
    }

    // Freq → band auto-fill
    const freqInput = document.getElementById('freq');
    const bandSelect = document.getElementById('band');
    if (freqInput && bandSelect) {
        freqInput.addEventListener('input', function() {
            const f = parseFloat(this.value);
            if (!isNaN(f) && f > 0) {
                const b = freqToBand(f);
                if (b) {
                    for (const opt of bandSelect.options) {
                        if (opt.value === b) { bandSelect.value = b; break; }
                    }
                }
            }
        });
    }

    // Callsign lookup on blur
    const callInput = document.getElementById('call');
    if (callInput) {
        callInput.addEventListener('blur', function() {
            const call = this.value.trim().toUpperCase();
            if (call.length < 3) return;
            this.value = call;
            fetch('/api/lookup.php?call=' + encodeURIComponent(call))
                .then(r => r.json())
                .then(data => {
                    if (data.name) {
                        const nameF = document.getElementById('name');
                        const qthF  = document.getElementById('qth');
                        const gridF = document.getElementById('gridsquare');
                        if (nameF && !nameF.value) nameF.value = data.name;
                        if (qthF  && !qthF.value && data.qth)  qthF.value = data.qth;
                        if (gridF && !gridF.value && data.grid) gridF.value = data.grid;
                    }
                })
                .catch(() => {});
        });
    }

    // RST default by mode
    const modeSelect = document.getElementById('mode');
    if (modeSelect) {
        modeSelect.addEventListener('change', function() {
            const rstS = document.getElementById('rst_sent');
            const rstR = document.getElementById('rst_rcvd');
            const cwModes = ['CW','RTTY','PSK31','PSK63','FT8','FT4','JS8','WSPR','JT65','JT9','MSK144'];
            const def = cwModes.includes(this.value) ? '599' : '59';
            if (rstS) rstS.value = def;
            if (rstR) rstR.value = def;
        });
    }

    // Confirm deletes
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', function(e) {
            if (!confirm(this.dataset.confirm || 'Are you sure?')) e.preventDefault();
        });
    });

    // Logbook row click → edit
    document.querySelectorAll('tr[data-href]').forEach(tr => {
        tr.addEventListener('click', function() {
            window.location = this.dataset.href;
        });
    });
});
