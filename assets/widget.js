/* Qhatuq – widget de chat. Sin dependencias; se monta en un Shadow DOM para aislarlo del tema. */
(function () {
	'use strict';

	var cfg = window.QhatuqConfig;
	if (!cfg || document.getElementById('qhatuq-host')) {
		return;
	}

	var state = { id: null, token: null, busy: false, loaded: false, hasHistory: false };
	var TEASER_KEY = cfg.storageKey + '_teaser';

	// ---------------------------------------------------------------- Almacenamiento
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

	function sessionFlag(key, value) {
		try {
			if (value === undefined) { return window.sessionStorage.getItem(key); }
			window.sessionStorage.setItem(key, value);
		} catch (e) { return null; }
		return null;
	}

	// ---------------------------------------------------------------- Utilidades
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
		var out = [];
		var inList = false;
		html.split('\n').forEach(function (line) {
			var m = line.match(/^\s*(?:[-•*]|\d+[.)])\s+(.*)$/);
			if (m) {
				if (!inList) { out.push('<ul>'); inList = true; }
				out.push('<li>' + m[1] + '</li>');
				return;
			}
			if (inList) { out.push('</ul>'); inList = false; }
			out.push(line === '' ? '<span class="gap"></span>' : '<p>' + line + '</p>');
		});
		if (inList) { out.push('</ul>'); }
		return out.join('');
	}

	// Elige texto blanco u oscuro según el color de marca, para mantener el contraste.
	function onColor(hex) {
		var m = /^#?([0-9a-f]{6})$/i.exec(hex || '');
		if (!m) { return '#ffffff'; }
		var n = parseInt(m[1], 16);
		var lin = [(n >> 16) & 255, (n >> 8) & 255, n & 255].map(function (v) {
			v /= 255;
			return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
		});
		var lum = 0.2126 * lin[0] + 0.7152 * lin[1] + 0.0722 * lin[2];
		return lum > 0.45 ? '#111827' : '#ffffff';
	}

	function time() {
		var d = new Date();
		return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
	}

	var ICONS = {
		chat: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3C6.5 3 2 6.8 2 11.5c0 2.4 1.2 4.6 3.1 6.1L4.3 21l4-1.9c1.2.4 2.4.6 3.7.6 5.5 0 10-3.8 10-8.4S17.5 3 12 3z" fill="currentColor"/></svg>',
		close: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>',
		minimize: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2.2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		send: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 12h13M12.5 6l6 6-6 6" stroke="currentColor" stroke-width="2.2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		lock: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10V8a5 5 0 0 1 10 0v2m-11 0h12v10H6z" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linejoin="round"/></svg>'
	};

	function avatar(size) {
		var a = el('span', 'avatar' + (size ? ' ' + size : ''));
		if (cfg.avatar) {
			var img = el('img');
			img.src = cfg.avatar;
			img.alt = '';
			a.appendChild(img);
		} else {
			a.textContent = (cfg.agentName || 'A').trim().charAt(0).toUpperCase();
		}
		return a;
	}

	// ---------------------------------------------------------------- Montaje
	var host = el('div');
	host.id = 'qhatuq-host';
	var shadow = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;

	var css = el('link');
	css.rel = 'stylesheet';
	css.href = cfg.cssUrl;
	shadow.appendChild(css);

	var theme = /^[a-z]+$/.test(cfg.theme || '') ? cfg.theme : 'clasico';
	var root = el('div', 'root theme-' + theme + ' ' + (cfg.position === 'left' ? 'left' : 'right'));
	root.style.setProperty('--accent', cfg.color || '#0b5cab');
	root.style.setProperty('--on-accent', onColor(cfg.color));
	root.hidden = true; // se muestra cuando cargue la hoja de estilos
	css.addEventListener('load', function () { root.hidden = false; });
	css.addEventListener('error', function () { root.hidden = false; });

	// Panel
	var panel = el('section', 'panel');
	panel.setAttribute('role', 'dialog');
	panel.setAttribute('aria-label', cfg.title);
	panel.setAttribute('aria-hidden', 'true');

	var header = el('header', 'header');
	var who = el('div', 'who');
	var avatarWrap = el('span', 'avatar-wrap');
	avatarWrap.appendChild(avatar());
	avatarWrap.appendChild(el('span', 'status-dot'));
	var titles = el('div', 'titles');
	titles.appendChild(el('strong', null, cfg.title));
	titles.appendChild(el('span', null, cfg.agentName + ' · En línea'));
	who.appendChild(avatarWrap);
	who.appendChild(titles);
	var minimize = el('button', 'icon-btn');
	minimize.type = 'button';
	minimize.setAttribute('aria-label', 'Minimizar chat');
	minimize.innerHTML = ICONS.minimize;
	header.appendChild(who);
	header.appendChild(minimize);

	var log = el('div', 'log');
	log.setAttribute('aria-live', 'polite');
	log.setAttribute('role', 'log');

	var notice = el('p', 'notice');
	notice.innerHTML = ICONS.lock;
	notice.appendChild(document.createTextNode(cfg.privacyNotice + ' '));
	if (cfg.privacyUrl) {
		var pl = el('a', null, 'Política de privacidad');
		pl.href = cfg.privacyUrl;
		pl.target = '_blank';
		pl.rel = 'noopener';
		notice.appendChild(pl);
	}
	log.appendChild(notice);

	var chips = el('div', 'chips');

	var form = el('form', 'composer');
	var field = el('div', 'field');
	var input = el('textarea', 'input');
	input.rows = 1;
	input.maxLength = cfg.maxChars || 1500;
	input.placeholder = 'Escriba su mensaje…';
	input.setAttribute('aria-label', 'Mensaje');
	var send = el('button', 'send');
	send.type = 'submit';
	send.disabled = true;
	send.setAttribute('aria-label', 'Enviar');
	send.innerHTML = ICONS.send;
	field.appendChild(input);
	field.appendChild(send);
	form.appendChild(field);

	panel.appendChild(header);
	panel.appendChild(log);
	panel.appendChild(chips);
	panel.appendChild(form);

	// Botón flotante e invitación
	var launcher = el('button', 'launcher');
	launcher.type = 'button';
	launcher.setAttribute('aria-label', 'Abrir chat con ' + cfg.title);
	launcher.setAttribute('aria-expanded', 'false');
	launcher.innerHTML = '<span class="ico ico-chat">' + ICONS.chat + '</span><span class="ico ico-close">' + ICONS.close + '</span>';
	if (cfg.launcherIcon === 'avatar') {
		// Foto del agente (o su inicial) en lugar del globo, con indicador de "en línea".
		var chatIco = launcher.querySelector('.ico-chat');
		chatIco.innerHTML = '';
		chatIco.classList.add('ico-avatar');
		chatIco.appendChild(avatar());
		chatIco.appendChild(el('span', 'launcher-dot'));
		launcher.classList.add('with-avatar');
	}
	if (cfg.launcherLabel) {
		// Solo visible en los temas que lo usan (p. ej., Vibrante).
		launcher.appendChild(el('span', 'launcher-label', cfg.launcherLabel));
		launcher.classList.add('has-label');
	}

	var teaser = el('div', 'teaser');
	teaser.hidden = true;
	var teaserText = el('button', 'teaser-text');
	teaserText.type = 'button';
	teaserText.appendChild(avatar('sm'));
	teaserText.appendChild(el('span', null, cfg.greeting));
	var teaserClose = el('button', 'teaser-close');
	teaserClose.type = 'button';
	teaserClose.setAttribute('aria-label', 'Cerrar invitación');
	teaserClose.innerHTML = ICONS.close;
	teaser.appendChild(teaserText);
	teaser.appendChild(teaserClose);

	root.appendChild(panel);
	root.appendChild(teaser);
	root.appendChild(launcher);
	shadow.appendChild(root);
	document.body.appendChild(host);

	// ---------------------------------------------------------------- Mensajes
	var lastRole = null;

	function addMessage(role, text, opts) {
		opts = opts || {};
		var row = el('div', 'row ' + role + (lastRole === role ? ' cont' : ''));
		if (role === 'assistant') {
			row.appendChild(lastRole === role ? el('span', 'avatar-space') : avatar('sm'));
		}
		var bubble = el('div', 'bubble' + (opts.error ? ' error' : ''));
		if (role === 'assistant') {
			bubble.innerHTML = format(text);
		} else {
			bubble.textContent = text;
		}
		var wrap = el('div', 'stack');
		wrap.appendChild(bubble);
		if (!opts.noTime) {
			wrap.appendChild(el('span', 'time', time()));
		}
		row.appendChild(wrap);
		log.appendChild(row);
		lastRole = role;
		scrollDown();
		return row;
	}

	function scrollDown() {
		log.scrollTop = log.scrollHeight;
	}

	var typingRow = null;
	var typingTimers = [];
	var WAIT_LABELS = ['Un momento, por favor…', 'Estoy verificando la información…', 'Ya casi…'];

	function typing(on) {
		typingTimers.forEach(clearTimeout);
		typingTimers = [];
		if (on && !typingRow) {
			typingRow = el('div', 'row assistant typing' + (lastRole === 'assistant' ? ' cont' : ''));
			typingRow.appendChild(lastRole === 'assistant' ? el('span', 'avatar-space') : avatar('sm'));
			var b = el('div', 'bubble');
			b.innerHTML = '<span class="dots"><i></i><i></i><i></i></span>';
			b.setAttribute('aria-label', 'Escribiendo');
			typingRow.appendChild(b);
			log.appendChild(typingRow);
			scrollDown();
			// Si la respuesta tarda (por ejemplo, porque el agente está verificando un
			// producto), se muestra un aviso que va cambiando.
			[5000, 14000, 26000].forEach(function (ms, i) {
				typingTimers.push(setTimeout(function () {
					if (!typingRow) { return; }
					var label = typingRow.querySelector('.wait') || el('span', 'wait');
					label.textContent = WAIT_LABELS[i];
					b.appendChild(label);
					scrollDown();
				}, ms));
			});
		} else if (!on && typingRow) {
			typingRow.remove();
			typingRow = null;
		}
	}

	function renderChips() {
		chips.innerHTML = '';
		if (state.hasHistory || !cfg.suggestions || !cfg.suggestions.length) {
			chips.hidden = true;
			return;
		}
		chips.hidden = false;
		cfg.suggestions.forEach(function (s) {
			var c = el('button', 'chip', s);
			c.type = 'button';
			c.addEventListener('click', function () { submitText(s); });
			chips.appendChild(c);
		});
	}

	// ---------------------------------------------------------------- Red
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
		addMessage('assistant', cfg.greeting, { noTime: true });
		renderChips();
		if (!state.id || !state.token) { return; }
		post('/history', { conversation_id: state.id, token: state.token }).then(function (res) {
			if (!res.ok) { forget(); return; }
			var msgs = res.data.messages || [];
			if (msgs.length) {
				state.hasHistory = true;
				renderChips();
			}
			msgs.forEach(function (m) { addMessage(m.role, m.content, { noTime: true }); });
		}).catch(function () { /* sin historial */ });
	}

	// ---------------------------------------------------------------- Abrir / cerrar
	function isOpen() {
		return root.classList.contains('open');
	}

	function open() {
		hideTeaser(true);
		root.classList.add('open');
		panel.setAttribute('aria-hidden', 'false');
		launcher.setAttribute('aria-expanded', 'true');
		launcher.setAttribute('aria-label', 'Cerrar chat');
		restore();
		setTimeout(function () { input.focus(); scrollDown(); }, 160);
	}

	function hide() {
		root.classList.remove('open');
		panel.setAttribute('aria-hidden', 'true');
		launcher.setAttribute('aria-expanded', 'false');
		launcher.setAttribute('aria-label', 'Abrir chat con ' + cfg.title);
		launcher.focus();
	}

	function hideTeaser(remember) {
		teaser.classList.remove('show');
		setTimeout(function () { teaser.hidden = true; }, 200);
		if (remember) { sessionFlag(TEASER_KEY, '1'); }
	}

	// ---------------------------------------------------------------- Envío
	function setBusy(on) {
		state.busy = on;
		send.disabled = on || !input.value.trim();
	}

	function submitText(text) {
		text = (text || '').trim();
		if (!text || state.busy) { return; }
		state.hasHistory = true;
		renderChips();
		setBusy(true);
		addMessage('user', text);
		typing(true);

		var body = { message: text, page_url: window.location.href };
		if (cfg.offerId) {
			body.context_offer = cfg.offerId;
		}
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
				addMessage('assistant', (res.data && res.data.message) || 'No pude enviar su mensaje. Intente nuevamente.', { error: true });
			}
		}).catch(function () {
			typing(false);
			addMessage('assistant', 'No hay conexión en este momento. Intente nuevamente.', { error: true });
		}).then(function () {
			setBusy(false);
			if (isOpen()) { input.focus(); }
		});
	}

	function autosize() {
		input.style.height = 'auto';
		input.style.height = Math.min(input.scrollHeight, 132) + 'px';
		send.disabled = state.busy || !input.value.trim();
	}

	launcher.addEventListener('click', function () { isOpen() ? hide() : open(); });
	minimize.addEventListener('click', hide);
	teaserText.addEventListener('click', open);
	teaserClose.addEventListener('click', function () { hideTeaser(true); });
	form.addEventListener('submit', function (e) {
		e.preventDefault();
		var text = input.value;
		input.value = '';
		autosize();
		submitText(text);
	});
	input.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
			e.preventDefault();
			form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true }));
		}
	});
	input.addEventListener('input', autosize);
	root.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && isOpen()) { hide(); }
	});

	load();

	// Invitación: una vez por visita y solo a quien aún no ha conversado.
	if (cfg.teaserDelay > 0 && !state.id && !sessionFlag(TEASER_KEY) && cfg.greeting) {
		setTimeout(function () {
			if (isOpen() || sessionFlag(TEASER_KEY)) { return; }
			teaser.hidden = false;
			requestAnimationFrame(function () { teaser.classList.add('show'); });
		}, cfg.teaserDelay * 1000);
	}
})();
