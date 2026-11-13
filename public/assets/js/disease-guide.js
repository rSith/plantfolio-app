// AI advice on a Health Check result (P08, ML-14): when Pl@ntNet named a problem that has no saved advice yet, the page
// shows grey bars and this script asks api/disease-guide.php to write the advice with the local LLM. The answer fills
// "What we see" and "What to do now"; if the LLM is not running or its answer cannot be read, a friendly line says so.
// Every AI sentence goes on the page with textContent, never as HTML.

(() => {
  const box = document.querySelector('[data-disease-guide]');
  if (!box) {
    return;
  }
  const status = box.querySelector('[data-guide-status] span');   // the words only, so the icon stays
  const symptoms = box.querySelector('[data-guide-symptoms]');
  const steps = box.querySelector('[data-guide-steps]');

  const showProblem = (state) => {
    box.querySelectorAll('[data-guide-part]').forEach((part) => part.remove());
    status.textContent = state === 'invalid'
      ? "The AI's advice could not be read. Reload the page to try again."
      : "Advice can't be written right now: the AI helper is not running. Compare the leaf with the name above, or ask the community.";
  };

  const body = new FormData();
  body.append('prediction', box.dataset.prediction);
  body.append('csrf_token', box.dataset.token);

  fetch(box.dataset.guideUrl, { method: 'POST', body, headers: { Accept: 'application/json' } })
    .then((response) => (response.ok ? response.json() : { state: 'offline' }))
    .then((answer) => {
      if (answer.state !== 'ready') {
        showProblem(answer.state);
        return;
      }
      symptoms.textContent = answer.symptoms;
      symptoms.classList.remove('diagnosis-skeleton');
      steps.replaceChildren(...answer.steps.map((text) => {
        const item = document.createElement('li');
        item.textContent = text;
        return item;
      }));
      steps.classList.remove('diagnosis-skeleton');
      box.querySelectorAll('[aria-hidden="true"][data-guide-part]').forEach((part) => part.removeAttribute('aria-hidden'));
      // The status line becomes the note that says who wrote the advice (role="status" reads it out)
      status.textContent = 'Advice written by AI. Check it against the leaf.';
    })
    .catch(() => showProblem('offline'));
})();
