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

const rows = document.querySelector('[data-message-rows]');
const search = document.querySelector('#message-search');
const reader = document.querySelector('[data-reader]');
const back = document.querySelector('[data-mailbox-back]');
const filters = document.querySelector('.mailbox-filters');
const fastItCard = document.querySelector('.mailbox-fast-it');
const wideInbox = window.matchMedia('(min-width: 850px)');
let selectedMessage;
let activeFilter = 'all';

function closeMessage() {
    if (!selectedMessage) return;

    selectedMessage.append(reader.querySelector('.mailbox-message-body'));
    selectedMessage.open = false;
    selectedMessage = undefined;
    reader.classList.remove('has-message');
    document.body.classList.remove('mailbox-reading');
    reader.removeAttribute('role');
    reader.removeAttribute('aria-label');
}

function updateReaderMode() {
    const mobileReading = Boolean(selectedMessage) && !wideInbox.matches;
    document.body.classList.toggle('mailbox-reading', mobileReading);
    if (mobileReading) {
        reader.setAttribute('role', 'region');
        reader.setAttribute('aria-label', 'Leitura da mensagem');
        back.focus();
    } else {
        reader.removeAttribute('role');
        reader.removeAttribute('aria-label');
    }
}

function updateList() {
    const term = search.value.trim().toLocaleLowerCase('pt-BR');
    const messages = Array.from(rows.querySelectorAll('[data-message]'));
    for (const message of messages) {
        const label = message.querySelector('[data-message-search]').textContent;
        const matchesFilter = activeFilter === 'all'
            || (activeFilter === 'unread' && message.hasAttribute('data-unread'))
            || (activeFilter === 'fast-it' && message.hasAttribute('data-fast-it-message'));
        message.hidden = !matchesFilter || !label.toLocaleLowerCase('pt-BR').includes(term);
    }
    const filtering = Boolean(term) || activeFilter !== 'all';
    document.querySelector('#message-search-empty').hidden = !filtering || messages.some((message) => !message.hidden);
    const emptyList = rows.querySelector('.mailbox-list-empty');
    if (emptyList) emptyList.hidden = filtering;
    document.querySelector('[data-message-count]').textContent = messages.length;
    document.querySelector('[data-unread-count]').textContent = messages.filter((message) => message.hasAttribute('data-unread')).length;
    if (messages.length > 0) {
        reader.querySelector('.mailbox-placeholder h2').textContent = 'Selecione uma mensagem';
        reader.querySelector('.mailbox-placeholder p').textContent = 'Escolha um e-mail na lista para ler a mensagem.';
    }
}

function updateFastItCard() {
    const message = rows.querySelector('[data-detected-code]');
    fastItCard.hidden = !message;
    if (!message) return;

    const sender = message.querySelector('[data-message-search] small').textContent;
    const time = message.querySelector('time').textContent;
    fastItCard.querySelector('[data-fast-it-info]').textContent = sender + ' · ' + time;
    fastItCard.querySelector('[data-fast-it-code]').textContent = message.dataset.detectedCode;
    fastItCard.querySelector('[data-copy]').dataset.copy = message.dataset.detectedCode;
}

function prepareMessage(message) {
    const panel = message.querySelector('[data-fast-it]');
    const code = panel && detectFastItCode(panel);
    if (code) {
        message.dataset.detectedCode = code;
        addCodeButton(panel, code);
    }
    message.addEventListener('toggle', async () => {
        if (!message.open) {
            if (selectedMessage === message) closeMessage();
            return;
        }
        if (selectedMessage !== message) {
            closeMessage();
            reader.append(message.querySelector('.mailbox-message-body'));
            reader.classList.add('has-message');
            selectedMessage = message;
            updateReaderMode();
        }
        if (!message.hasAttribute('data-unread')) return;

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
                updateList();
            }
        } catch {
            // Keep the unread marker so opening the message again can retry.
        }
    });
}

if (rows) {
    search.addEventListener('input', updateList);
    filters.hidden = false;
    filters.addEventListener('click', (event) => {
        const button = event.target.closest('[data-message-filter]');
        if (!button) return;
        activeFilter = button.dataset.messageFilter;
        filters.querySelectorAll('button').forEach((item) => {
            item.setAttribute('aria-pressed', String(item === button));
        });
        updateList();
    });
    wideInbox.addEventListener('change', updateReaderMode);
    back.addEventListener('click', () => {
        const summary = selectedMessage?.querySelector('summary');
        closeMessage();
        summary?.focus();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && selectedMessage && !wideInbox.matches) back.click();
    });
    rows.querySelectorAll('[data-message]').forEach(prepareMessage);
    updateFastItCard();

    async function refreshMessages() {
        if (document.hidden) return;

        try {
            const url = new URL(location.href);
            url.searchParams.set('messages_only', '1');
            const response = await fetch(url, { cache: 'no-store', redirect: 'error' });
            if (!response.ok) return;
            const template = document.createElement('template');
            template.innerHTML = await response.text();
            const existing = new Set(Array.from(rows.querySelectorAll('[data-message]'), (message) => message.dataset.messageId));
            const newMessages = Array.from(template.content.querySelectorAll('[data-message]'))
                .filter((message) => !existing.has(message.dataset.messageId));
            if (!newMessages.length) return;

            rows.querySelector('.mailbox-list-empty')?.remove();
            newMessages.forEach(prepareMessage);
            rows.prepend(...newMessages);
            updateList();
            updateFastItCard();
        } catch {
            // A later refresh can retry without interrupting the reader.
        }
    }

    async function pollMessages() {
        await refreshMessages();
        setTimeout(pollMessages, 15000);
    }
    setTimeout(pollMessages, 15000);
}

function detectFastItCode(panel) {
    const content = panel.dataset.fastItSubject + '\n' + panel.dataset.fastIt;
    const pattern = /\b(?:c[oó]digo(?:\s+de\s+(?:verifica[cç][aã]o|ativa[cç][aã]o))?|codice(?:\s+(?:di\s+(?:verifica|attivazione)|OTP))?|(?:verification|activation)\s+code)\s*(?:[:=-]\s*)?(\d{4,8})\b/gi;
    const codes = new Set(Array.from(content.matchAll(pattern), (match) => match[1]));
    return codes.size === 1 ? [...codes][0] : null;
}

function addCodeButton(panel, code) {
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
