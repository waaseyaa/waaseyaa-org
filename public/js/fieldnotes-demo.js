'use strict';
// All demo data is in memory. There are no fetches, external services, or persistent writes.
const story = { title: 'A little space to grow.', author: 'Jamie Lee', status: 'draft', collection: 'Community stories' };
const $ = (selector) => document.querySelector(selector);
const $$ = (selector) => [...document.querySelectorAll(selector)];

function updateStory() {
  if (!$('#story-status')) return;
  $('#story-status').textContent = story.status === 'published' ? 'Published' : 'Draft';
  $('#preview-state').textContent = story.status === 'published' ? 'PUBLISHED IN THIS DEMO' : 'DRAFT PREVIEW';
  $('#api-output').textContent = JSON.stringify({ data: { type: 'story', id: 'garden-01', attributes: story } }, null, 2);
}
function showDemo(view) {
  ['editor', 'preview', 'api'].forEach(name => { $(`#${name}-view`).hidden = name !== view; });
  $$('[data-demo]').forEach(button => { const active = button.dataset.demo === view; button.classList.toggle('active', active); button.setAttribute('aria-pressed', String(active)); });
}
if ($('#review-button')) {
  updateStory();
  $$('[data-demo]').forEach(button => button.addEventListener('click', () => showDemo(button.dataset.demo)));
  $('#review-button').addEventListener('click', () => {
    if (story.status === 'published') { showDemo('preview'); return; }
    $('#review-error').textContent = ''; $('#review-check').checked = false; $('#review-dialog').showModal();
  });
  $('#confirm-publish').addEventListener('click', () => {
    if (!$('#review-check').checked) { $('#review-error').textContent = 'Review the story and check the box before continuing.'; $('#review-check').focus(); return; }
    story.status = 'published'; updateStory();
    $('#review-step').classList.add('done'); $('#publish-step').classList.add('done');
    $('#review-step-note').textContent = 'Approved in this demo';
    $('#review-button').innerHTML = 'See public view ';
    $('#demo-feedback').textContent = 'Published locally. No content was sent online.';
    $('#review-dialog').close();
  });
  $('#reset-demo').addEventListener('click', () => {
    story.status = 'draft'; updateStory();
    $('#review-step').classList.remove('done'); $('#publish-step').classList.remove('done');
    $('#review-step-note').textContent = 'An editor approves';
    $('#review-button').innerHTML = 'Review &amp; publish ';
    $('#demo-feedback').textContent = 'Example reset. Everything stays in your browser.';
  });
}

