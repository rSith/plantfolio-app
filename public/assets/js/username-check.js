// Sign up (P02) and Settings (P15): the live "is this username free?" check (CORE-08, CORE-26).
// While the member types, asks api/check-username.php and shows the answer under the field. It only helps:
// if anything goes wrong the line stays empty, and the page checks everything again on submit.

(() => {
  const status = document.querySelector('[data-username-status]');
  const input = document.getElementById('username');
  if (!status || !input) {
    return;
  }

  const checkUrl = status.dataset.checkUrl;
  const currentUsername = status.dataset.currentUsername || '';   // Settings: the member's own name is not "taken"
  const pattern = /^[a-z0-9._]{3,30}$/;   // the same rule as username_problem() in includes/auth.php
  let timer = null;
  let request = null;   // the AbortController of the request still on its way, if any

  function show(message, kind) {
    status.textContent = message;
    status.classList.toggle('field-ok', kind === 'ok');
    status.classList.toggle('field-error', kind === 'taken');
  }

  async function check(username) {
    // A newer check replaces an older one still on its way, so a slow old answer can never overwrite a new one
    if (request) {
      request.abort();
    }
    request = new AbortController();

    try {
      const url = checkUrl + (checkUrl.includes('?') ? '&' : '?') + 'username=' + encodeURIComponent(username);
      const response = await fetch(url, { signal: request.signal, headers: { Accept: 'application/json' } });
      if (!response.ok) {
        throw new Error('HTTP ' + response.status);
      }
      const answer = await response.json();
      // The visitor may have typed on while we waited: only show the answer if it is still about this name
      if (input.value.trim().toLowerCase() === username) {
        show(answer.message, answer.available ? 'ok' : 'taken');
      }
    } catch (error) {
      if (error.name !== 'AbortError') {
        show('', null);   // offline or a server problem: say nothing, the check on submit still runs
      }
    }
  }

  input.addEventListener('input', () => {
    clearTimeout(timer);
    show('', null);
    const username = input.value.trim().toLowerCase();
    // Too short or a wrong character: the hint under the field already says the rule, and the browser checks it
    if (!pattern.test(username) || username === currentUsername) {
      return;
    }
    // Wait until typing pauses for 0.4 s, so "nimali.grows" sends one request, not twelve
    timer = setTimeout(() => check(username), 400);
  });
})();
