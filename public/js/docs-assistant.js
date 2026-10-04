'use strict';
document.getElementById('chat-clear')?.addEventListener('click', () => {
  fetch('/docs-chat/clear', {method: 'POST'}).then(response => {
    if (!response.ok) throw new Error('Unable to clear conversation');
    localStorage.removeItem('ws.chat.docs.pub');
    window.location.reload();
  }).catch(() => { document.getElementById('chat-clear').textContent = 'Could not clear. Try again.'; });
});
if (window.WaaseyaaChat) {
  window.WaaseyaaChat.mount(document.querySelector('[data-ws-chat]'), {
    endpoints: {send: '/docs-chat/send', apply: '/docs-chat/send', messages: id => '/docs-chat/' + id + '/messages'},
    thread: {key: 'ws.chat.docs.pub', initial: 0},
    user: {id: 0, label: 'You'}, limit: 500,
    chips: ['How do I add an entity type?', 'How does field access work?'],
    avatars: {user: 'YOU', ai: 'W'}
  });
}
