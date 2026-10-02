/* Qhatuq – widget de chat. Sin dependencias. */
(function () {
	'use strict';

	var cfg = window.QhatuqConfig;
	if (!cfg || document.getElementById('qhatuq-root')) {
		return;
	}

	var state = { id: null, token: null, busy: false, loaded: false };

	function load() {
		try {
			var raw = window.localStorage.getItem(cfg.storageKey);
			if (raw) {
				var s = JSON.parse(raw);
				state.id = s.id || null;
				state.token = s.token || null;
			}
		} catch (e) { /* almacenamiento no disponible */ }
	}

	function save() {
		try {
			window.localStorage.setItem(cfg.storageKey, JSON.stringify({ id: state.id, token: state.token }));
		} catch (e) { /* almacenamiento no disponible */ }
	}

	function forget() {
		state.id = null;
		state.token = null;
		try { window.localStorage.removeItem(cfg.storageKey); } catch (e) { /* nada */ }
	}

	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		if (text) { n.textContent = text; }
		return n;
	}

	function escapeHtml(s) {
		return s.replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	// Markdown mínimo y seguro: se escapa todo y luego se aplican negritas, enlaces y listas.
	function format(text) {
		var html = escapeHtml(text);
		html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
		html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener nofollow">$1</a>');
		html = html.replace(/(^|[\s(])(https?:\/\/[^\s<)]+)/g, '$1<a href="$2" target="_blank" rel="noopener nofollow">$2</a>');
		var lines = html.split('\n');
		var out = [];
		var inList = false;
		lines.forEach(function (line) {
			var m = line.match(/^\s*[-•*]\s+(.*)$/);
			if (m) {
				if (!inList) { out.push('<ul>'); inList = true; }
				out.push('<li>' + m[1] + '</li>');
			} else {
				if (inList) { out.push('</ul>'); inList = false; }
				out.push(line === '' ? '<br>' : line + '<br>');
			}
		});
		if (inList) { out.push('</ul>'); }
		return out.join('').replace(/(<br>)+$/, '');
	}

	// ---------------------------------------------------------------- Interfaz
	var root = el('div', 'qhatuq-root qhatuq-' + (cfg.position === 'left' ? 'left' : 'right'));
	root.id = 'qhatuq-root';
	root.style.setProperty('--qhatuq-color', cfg.color || '#0b5cab');

	var launcher = el('button', 'qhatuq-launcher');
	launcher.type = 'button';
	launcher.setAttribute('aria-label', 'Abrir chat con ' + cfg.title);
	launcher.innerHTML = '<svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true"><path fill="currentColor" d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8l-4 4V6a2 2 0 0 1 2-2z"/></svg>';

	var panel = el('div', 'qhatuq-panel');
	panel.setAttribute('role', 'dialog');
	panel.setAttribute('aria-label', cfg.title);
	panel.hidden = true;

	var header = el('div', 'qhatuq-header');
	var titles = el('div', 'qhatuq-titles');
	titles.appendChild(el('strong', null, cfg.title));
	titles.appendChild(el('span', null, cfg.agentName + ' · asistente virtual'));
	var close = el('button', 'qhatuq-close');
	close.type = 'button';
	close.setAttribute('aria-label', 'Cerrar chat');
	close.textContent = '×';
	header.appendChild(titles);
	header.appendChild(close);

	var log = el('div', 'qhatuq-log');
	log.setAttribute('aria-live', 'polite');

	var notice = el('div', 'qhatuq-notice');
	notice.textContent = cfg.privacyNotice + ' ';
	if (cfg.privacyUrl) {
		var a = el('a', null, 'Política de privacidad');
		a.href = cfg.privacyUrl;
		a.target = '_blank';
		a.rel = 'noopener';
		notice.appendChild(a);
	}

	var form = el('form', 'qhatuq-form');
	var input = el('textarea', 'qhatuq-input');
	input.rows = 1;
	input.maxLength = cfg.maxChars || 1500;
	input.placeholder = 'Escriba su mensaje…';
	input.setAttribute('aria-label', 'Mensaje');
	var send = el('button', 'qhatuq-send', 'Enviar');
	send.type = 'submit';
	form.appendChild(input);
	form.appendChild(send);

	panel.appendChild(header);
	panel.appendChild(log);
	panel.appendChild(notice);
	panel.appendChild(form);
	root.appendChild(panel);
	root.appendChild(launcher);
	document.body.appendChild(root);

	function addMessage(role, text) {
		var bubble = el('div', 'qhatuq-msg qhatuq-' + role);
		if (role === 'assistant') {
			bubble.innerHTML = format(text);
		} else {
			bubble.textContent = text;
		}
		log.appendChild(bubble);
		log.scrollTop = log.scrollHeight;
		return bubble;
	}

	function typing(on) {
		var t = log.querySelector('.qhatuq-typing');
		if (on && !t) {
			t = el('div', 'qhatuq-msg qhatuq-assistant qhatuq-typing');
			t.innerHTML = '<span></span><span></span><span></span>';
			log.appendChild(t);
			log.scrollTop = log.scrollHeight;
		} else if (!on && t) {
			t.remove();
		}
	}

	function post(path, body) {
		return fetch(cfg.endpoint + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			credentials: 'omit',
			body: JSON.stringify(body)
		}).then(function (r) {
			return r.json().then(function (data) { return { ok: r.ok, status: r.status, data: data }; });
		});
	}

	function restore() {
		if (state.loaded) { return; }
		state.loaded = true;
		addMessage('assistant', cfg.greeting);
		if (!state.id || !state.token) { return; }
		post('/history', { conversation_id: state.id, token: state.token }).then(function (res) {
			if (!res.ok) { forget(); return; }
			(res.data.messages || []).forEach(function (m) { addMessage(m.role, m.content); });
		}).catch(function () { /* sin historial */ });
	}

	function open() {
		panel.hidden = false;
		launcher.setAttribute('aria-expanded', 'true');
		root.classList.add('qhatuq-open');
		restore();
		setTimeout(function () { input.focus(); }, 50);
	}

	function hide() {
		panel.hidden = true;
		launcher.setAttribute('aria-expanded', 'false');
		root.classList.remove('qhatuq-open');
	}

	function submit(e) {
		if (e) { e.preventDefault(); }
		var text = input.value.trim();
		if (!text || state.busy) { return; }
		state.busy = true;
		send.disabled = true;
		input.value = '';
		input.style.height = '';
		addMessage('user', text);
		typing(true);

		var body = { message: text, page_url: window.location.href };
		if (state.id && state.token) {
			body.conversation_id = state.id;
			body.token = state.token;
		}
		post('/chat', body).then(function (res) {
			typing(false);
			if (res.ok) {
				if (res.data.token) {
					state.token = res.data.token;
				}
				state.id = res.data.conversation_id;
				save();
				addMessage('assistant', res.data.reply);
			} else {
				addMessage('assistant', (res.data && res.data.message) || 'No pude enviar su mensaje. Intente nuevamente.');
			}
		}).catch(function () {
			typing(false);
			addMessage('assistant', 'No hay conexión en este momento. Intente nuevamente.');
		}).then(function () {
			state.busy = false;
			send.disabled = false;
			input.focus();
		});
	}

	launcher.addEventListener('click', function () { panel.hidden ? open() : hide(); });
	close.addEventListener('click', hide);
	form.addEventListener('submit', submit);
	input.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && !e.shiftKey) { submit(e); }
	});
	input.addEventListener('input', function () {
		input.style.height = 'auto';
		input.style.height = Math.min(input.scrollHeight, 120) + 'px';
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && !panel.hidden) { hide(); }
	});

	load();
})();
