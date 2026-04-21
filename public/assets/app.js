// Team builder (χρησιμοποιείται στη φόρμα δήλωσης ομάδας)
document.addEventListener('DOMContentLoaded', function () {
  const builder = document.querySelector('[data-team-builder]');
  if (!builder) return;

  const maxSize = parseInt(builder.dataset.teamSize, 10) || 2;
  const category = builder.dataset.category || 'M';
  const rows = Array.from(builder.querySelectorAll('[data-player-row]'));
  const summary = document.querySelector('[data-selected-summary]');
  const hidden = document.querySelector('[data-selected-input]');
  const submitBtn = document.querySelector('[data-submit-team]');

  function refresh() {
    const selected = rows.filter(r => r.classList.contains('player-row--selected'));
    const codes = selected.map(r => r.dataset.playercode);
    if (hidden) hidden.value = codes.join('-');

    // Κατάσταση checkboxes
    rows.forEach(r => {
      const isSel = r.classList.contains('player-row--selected');
      const disabled = !isSel && selected.length >= maxSize;
      r.classList.toggle('player-row--disabled', disabled);
      const cb = r.querySelector('input[type="checkbox"]');
      if (cb) {
        cb.checked = isSel;
        cb.disabled = disabled;
      }
    });

    // Summary
    if (summary) {
      if (selected.length === 0) {
        summary.innerHTML = '<div class="muted">Δεν έχετε επιλέξει παίκτες.</div>';
      } else {
        summary.innerHTML = selected.map(r => {
          return '<div class="player-row">'
            + '<div><strong>' + escapeHtml(r.dataset.firstname) + ' ' + escapeHtml(r.dataset.lastname) + '</strong>'
            + ' <span class="muted">(' + escapeHtml(r.dataset.playercode) + ')</span></div>'
            + '<span class="badge badge--' + escapeHtml(r.dataset.gender) + '">' + (r.dataset.gender === 'M' ? 'Α' : 'Γ') + '</span>'
            + '</div>';
        }).join('');
      }
    }

    // Submit enable όταν έχει σωστό πλήθος + για MIX απαιτείται 1Α+1Γ
    let valid = selected.length === maxSize;
    if (category === 'MIX') {
      const genders = selected.map(r => r.dataset.gender).sort().join(',');
      valid = valid && genders === 'F,M';
    } else if (category === 'M') {
      valid = valid && selected.every(r => r.dataset.gender === 'M');
    } else if (category === 'F') {
      valid = valid && selected.every(r => r.dataset.gender === 'F');
    }
    if (submitBtn) submitBtn.disabled = !valid;
  }

  rows.forEach(r => {
    r.addEventListener('click', function (ev) {
      if (ev.target.tagName === 'INPUT') return; // αφήνει το checkbox να κάνει toggle μόνο του
      const cb = r.querySelector('input[type="checkbox"]');
      if (cb && !cb.disabled) {
        cb.checked = !cb.checked;
        r.classList.toggle('player-row--selected', cb.checked);
        refresh();
      } else if (r.classList.contains('player-row--selected')) {
        r.classList.remove('player-row--selected');
        if (cb) cb.checked = false;
        refresh();
      }
    });
    const cb = r.querySelector('input[type="checkbox"]');
    if (cb) {
      cb.addEventListener('change', function () {
        r.classList.toggle('player-row--selected', cb.checked);
        refresh();
      });
    }
  });

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, m => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[m]);
  }

  refresh();
});

// Auth tabs (login page)
document.addEventListener('DOMContentLoaded', function () {
  const tabs = document.querySelectorAll('[data-auth-tab]');
  const panels = document.querySelectorAll('[data-auth-panel]');
  tabs.forEach(t => t.addEventListener('click', function () {
    tabs.forEach(x => x.classList.remove('auth__tab--active'));
    panels.forEach(p => p.hidden = true);
    t.classList.add('auth__tab--active');
    const panel = document.querySelector('[data-auth-panel="' + t.dataset.authTab + '"]');
    if (panel) panel.hidden = false;
  }));
});
