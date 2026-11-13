// The AI care guide card on the Plant Profile (P05) and on an Identify result (P07): when no guide is saved yet, the
// card shows "Generating" and this script asks api/care-guide.php to write one with the local LLM (Phase 5, ML-12).
// On Identify the card sits inside the closed "Read care guide" <details>: the LLM is only asked once it is opened.
// The answer fills the five small cards; if the LLM is not running or its answer cannot be read, the page shows the
// friendly message for that instead.
// Every AI sentence goes on the page with textContent, never as HTML.

(() => {
  const card = document.querySelector('[data-guide-state="generating"]');
  if (!card) {
    return;
  }

  const showProblem = (state) => {
    // The server draws the message for 'offline', 'invalid' and 'no-species' (partials/care-guide.php)
    const address = new URL(window.location.href);
    address.searchParams.set('guide', state);
    address.hash = 'care-guide';
    window.location.replace(address.toString());
  };

  const generate = () => {
    const body = new FormData();
    body.append(card.dataset.sourceName, card.dataset.sourceId);
    body.append('csrf_token', card.dataset.token);

    fetch(card.dataset.guideUrl, { method: 'POST', body, headers: { Accept: 'application/json' } })
      .then((response) => (response.ok ? response.json() : { state: 'offline' }))
      .then((answer) => {
        if (answer.state !== 'ready') {
          showProblem(['invalid', 'no-species'].includes(answer.state) ? answer.state : 'offline');
          return;
        }
        card.querySelectorAll('[data-guide-key]').forEach((item) => {
          item.textContent = answer.sections[item.dataset.guideKey] || '';
          item.classList.remove('ai-guide-skeleton');
        });
        card.querySelector('.ai-guide-list').removeAttribute('aria-hidden');
        const meta = document.createElement('p');
        meta.className = 'ai-guide-meta';
        const name = document.createElement('em');
        name.textContent = answer.species;
        meta.append('For ', name, ` · ${answer.date}`);
        card.querySelector('.ai-guide-head').append(meta);
        // The status line becomes the announcement that the guide is ready (role="status" is read out)
        const status = card.querySelector('.ai-guide-status');
        status.textContent = 'Care guide ready.';
        status.classList.add('visually-hidden');
      })
      .catch(() => showProblem('offline'));
  };

  // Inside a closed <details>, wait for the member to open it (once): an unread guide is not worth a minute of LLM
  const details = card.closest('details');
  if (details && !details.open) {
    details.addEventListener('toggle', generate, { once: true });
  } else {
    generate();
  }
})();
