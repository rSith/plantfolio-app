// The Plant Profile's own script (P05): the Add to story and Add a photo popups (CORE-19, CORE-17).
// Loaded through $page_js by plant.php. The popups are normal forms: the server checks, saves and shows the page
// again (plant.php, form=story and form=photo). This script only helps before sending: it explains a missing note,
// checks a chosen photo (type, 5 MB) and shows it. Without JavaScript the forms still work.
// (Mark done in the Reminders card is a server form too, like the Dashboard's.)

const isoDay = (date) => {
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${date.getFullYear()}-${month}-${day}`;
};

// ── Add to story popup ──────────────────────────────────────────────────────

const storyForm = document.getElementById('story-form');

if (storyForm) {
  const fields = storyForm.elements;
  const note = fields.note;
  const noteError = storyForm.querySelector('[data-note-error]');
  const noteOptional = storyForm.querySelector('[data-note-optional]');
  const photo = fields.photo;
  const photoPreview = storyForm.querySelector('[data-photo-preview]');
  const photoHint = storyForm.querySelector('[data-photo-hint]');
  const photoButton = storyForm.querySelector('[data-photo-button]');

  // A note or milestone needs words; a care chip does not
  const needsNote = () => ['note', 'milestone'].includes(fields.kind.value);
  const showNoteRule = () => {
    noteOptional.hidden = needsNote();
  };

  function clearPhoto() {
    photo.value = '';
    photoPreview.hidden = true;
    photoPreview.removeAttribute('src');
    photoButton.textContent = 'Add a photo';
    photoHint.textContent = 'JPG or PNG, up to 5 MB.';
    photoHint.classList.remove('field-error');
  }

  function resetForm() {
    storyForm.reset();
    fields.date.value = isoDay(new Date());
    clearPhoto();
    noteError.hidden = true;
    note.removeAttribute('aria-invalid');
    showNoteRule();
  }

  storyForm.querySelectorAll('.story-kind-input').forEach((input) => input.addEventListener('change', showNoteRule));
  showNoteRule();

  // Photo: check the type and size before showing a preview (the server checks again)
  photo.addEventListener('change', () => {
    const file = photo.files[0];
    if (!file) {
      clearPhoto();
      return;
    }
    const problem = photoProblem(file);   // app.js: JPG or PNG, 5 MB at most
    if (problem) {
      clearPhoto();
      photoHint.textContent = problem;
      photoHint.classList.add('field-error');
      return;
    }
    photoPreview.src = URL.createObjectURL(file);
    photoPreview.hidden = false;
    photoButton.textContent = 'Change photo';
    photoHint.textContent = photoWords(file);
    photoHint.classList.remove('field-error');
  });

  storyForm.addEventListener('submit', (event) => {
    // Cancel and × close the popup (formmethod="dialog"): let them
    if (event.submitter && event.submitter.getAttribute('formmethod') === 'dialog') {
      resetForm();
      return;
    }
    // A note or milestone needs words: say so here, before the page is sent. Otherwise the form goes to the server,
    // which checks everything again, saves the entry and shows the page with it.
    if (needsNote() && note.value.trim() === '') {
      event.preventDefault();
      noteError.hidden = false;
      note.setAttribute('aria-invalid', 'true');
      note.focus();
    }
  });
}

// ── Add a photo popup ───────────────────────────────────────────────────────
// Choose or drag a photo, check it and show it in the drop area; Add photo then sends it to the server.

const photoForm = document.getElementById('photo-form');

if (photoForm) {
  const input = photoForm.elements.photo;
  const drop = photoForm.querySelector('[data-photo-drop]');
  const preview = photoForm.querySelector('[data-photo-preview]');
  const empty = photoForm.querySelector('[data-photo-empty]');
  const hint = photoForm.querySelector('[data-photo-hint]');
  const hintText = hint.textContent;

  function showHint(text, isError) {
    hint.textContent = text;
    hint.classList.toggle('field-error', isError);
  }

  function clearPhoto() {
    photoForm.reset();
    photoForm.elements.date.value = isoDay(new Date());
    preview.hidden = true;
    preview.removeAttribute('src');
    empty.hidden = false;
    showHint(hintText, false);
  }

  // The same checks the server makes (finfo, 5 MB): photoProblem() in app.js
  function usePhoto(file) {
    if (!file) {
      clearPhoto();
      return;
    }
    const problem = photoProblem(file);
    if (problem) {
      clearPhoto();
      showHint(problem, true);
      return;
    }
    preview.src = URL.createObjectURL(file);
    preview.hidden = false;
    empty.hidden = true;
    showHint(photoWords(file), false);
  }

  input.addEventListener('change', () => usePhoto(input.files[0]));

  // Drag and drop: the dropped file is put into the file input, as if it had been chosen
  drop.addEventListener('dragover', (event) => {
    event.preventDefault();
    drop.classList.add('is-dragging');
  });
  drop.addEventListener('dragleave', () => drop.classList.remove('is-dragging'));
  drop.addEventListener('drop', (event) => {
    event.preventDefault();
    drop.classList.remove('is-dragging');
    if (event.dataTransfer.files.length) {
      input.files = event.dataTransfer.files;
      usePhoto(input.files[0]);
    }
  });

  photoForm.addEventListener('submit', (event) => {
    // Cancel and × close the popup (formmethod="dialog"): let them
    if (event.submitter && event.submitter.getAttribute('formmethod') === 'dialog') {
      clearPhoto();
      return;
    }
    // No photo chosen (or a refused one): say so here. Otherwise the form goes to the server, which checks the
    // photo again, saves it and shows the page with it.
    if (preview.hidden) {
      event.preventDefault();
      showHint('Choose a photo first.', true);
      input.focus();
    }
  });
}
