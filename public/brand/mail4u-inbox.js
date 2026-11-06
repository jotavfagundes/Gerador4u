document.addEventListener('click', async (event) => {
    const button = event.target.closest('button[data-copy]');
    if (!button) return;

    const label = button.textContent;
    button.disabled = true;
    try {
        await navigator.clipboard.writeText(button.dataset.copy);
        button.textContent = 'Copiado';
    } catch {
        button.textContent = 'Não foi possível copiar';
    }
    setTimeout(() => {
        button.textContent = label;
        button.disabled = false;
    }, 1800);
});

const search = document.querySelector('#message-search');
const messages = document.querySelectorAll('[data-message]');
const reader = document.querySelector('[data-reader]');
const wideInbox = window.matchMedia('(min-width: 850px)');
let selectedMessage;

function returnMessageToList() {
    if (!selectedMessage) return;
    selectedMessage.append(reader.querySelector('.mailbox-message-body'));
    reader.classList.remove('has-message');
    selectedMessage = undefined;
}

wideInbox.addEventListener('change', () => {
    if (!wideInbox.matches) returnMessageToList();
});
search?.addEventListener('input', () => {
    const term = search.value.trim().toLocaleLowerCase('pt-BR');
    let found = false;
    for (const message of messages) {
        const label = message.querySelector('[data-message-search]').textContent;
        message.hidden = !label.toLocaleLowerCase('pt-BR').includes(term);
        found ||= !message.hidden;
    }
    document.querySelector('#message-search-empty').hidden = found;
});

for (const message of messages) {
    message.addEventListener('toggle', async () => {
        if (!message.open && selectedMessage === message) returnMessageToList();
        if (message.open && wideInbox.matches && selectedMessage !== message) {
            const previous = selectedMessage;
            returnMessageToList();
            if (previous) previous.open = false;
            reader.append(message.querySelector('.mailbox-message-body'));
            reader.classList.add('has-message');
            selectedMessage = message;
        }
        if (!message.open || !message.hasAttribute('data-unread')) return;

        const body = new URLSearchParams({
            action: 'mark-read',
            message_id: message.dataset.messageId,
            _token: message.dataset.csrf,
        });
        try {
            const response = await fetch(location.pathname, { method: 'POST', body, redirect: 'error' });
            if (response.status === 204) {
                message.removeAttribute('data-unread');
                message.querySelector('[data-unread-label]')?.remove();
            }
        } catch {
            // Keep the unread marker so opening the message again can retry.
        }
    });
}

for (const panel of document.querySelectorAll('[data-fast-it]')) {
    const content = panel.dataset.fastItSubject + '\n' + panel.dataset.fastIt;
    const pattern = /\b(?:c[oó]digo(?:\s+de\s+verifica[cç][aã]o)?|codice(?:\s+di\s+verifica)?|verification\s+code)\s*(?:[:=-]\s*)?(\d{4,8})\b/gi;
    const codes = new Set(Array.from(content.matchAll(pattern), (match) => match[1]));
    if (codes.size !== 1) continue;

    const [code] = codes;
    const value = document.createElement('code');
    value.textContent = code;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'button is-small ml-2';
    button.dataset.copy = code;
    button.textContent = 'Copiar código';
    panel.classList.add('mt-3');
    panel.append(value, button);
}
