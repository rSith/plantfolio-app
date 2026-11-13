// Add a plant (P06, ML-13): two small helpers for the AI parts of the form.
// 1. Identify plant: while the photo is with the Plant Identifier (a few seconds), the button says "Identifying…" and
//    cannot be pressed twice. The form itself is sent by the browser as usual.
// 2. About <species>: when the species the AI filled in has no saved introduction yet, ask api/species-about.php to
//    write it with the local LLM. Every AI sentence goes on the page with textContent, never as HTML.

(() => {
  const identify = document.querySelector('[data-identify-button]');
  if (identify) {
    identify.form.addEventListener('submit', (event) => {
      if (event.submitter !== identify) {
        return;
      }
      identify.querySelector('span').textContent = 'Identifying…';
      identify.setAttribute('aria-busy', 'true');
      // Disabled only after the browser has read the form: a disabled button's action=identify would not be sent
      setTimeout(() => { identify.disabled = true; }, 0);
    });
  }

  const card = document.querySelector('[data-species-about]');
  if (!card) {
    return;
  }
  const text = card.querySelector('[data-about-text]');
  const status = card.querySelector('[data-about-status] span');   // the words only, so the icon stays

  const showProblem = (state) => {
    text.remove();
    status.textContent = state === 'invalid'
      ? "The AI's introduction could not be read. Reload the page to try again."
      : "An introduction can't be written right now: the AI helper is not running.";
  };

  const body = new FormData();
  body.append('prediction', card.dataset.prediction);
  body.append('pick', card.dataset.pick);
  body.append('csrf_token', card.dataset.token);

  fetch(card.dataset.aboutUrl, { method: 'POST', body, headers: { Accept: 'application/json' } })
    .then((response) => (response.ok ? response.json() : { state: 'offline' }))
    .then((answer) => {
      if (answer.state !== 'ready') {
        showProblem(answer.state);
        return;
      }
      text.textContent = answer.about;
      text.classList.remove('about-skeleton');
      text.removeAttribute('aria-hidden');
      status.textContent = 'Written by AI';
    })
    .catch(() => showProblem('offline'));
})();
