/* Qhatuq – pestañas de Ajustes y vista previa en vivo del chat. */
(function () {
	'use strict';

	var cfg = window.QhatuqAdmin || {};
	var form = document.getElementById('qhatuq-settings-form');
	if (!form) {
		return;
	}

	// ---------------------------------------------------------------- Pestañas
	var links = Array.prototype.slice.call(document.querySelectorAll('.qhatuq-tabs .nav-tab'));
	var panels = Array.prototype.slice.call(document.querySelectorAll('.qhatuq-tab'));
	var save = document.querySelector('.qhatuq-save');
	var STORE = 'qhatuq_settings_tab';

	function show(tab) {
		if (!links.some(function (a) { return a.dataset.tab === tab; })) {
			tab = 'general';
		}
		links.forEach(function (a) { a.classList.toggle('nav-tab-active', a.dataset.tab === tab); });
		panels.forEach(function (p) { p.classList.toggle('is-active', p.dataset.tab === tab); });
		// "Herramientas" está fuera del formulario: ahí no corresponde el botón de guardar.
		if (save) { save.style.display = tab === 'herramientas' ? 'none' : ''; }
		try { window.localStorage.setItem(STORE, tab); } catch (e) { /* sin almacenamiento */ }
		if (tab === 'apariencia') { render(); }
	}

	links.forEach(function (a) {
		a.addEventListener('click', function (e) {
			e.preventDefault();
			show(a.dataset.tab);
			if (window.history && window.history.replaceState) {
				window.history.replaceState(null, '', '#' + a.dataset.tab);
			}
		});
	});

	// Si un campo inválido está en una pestaña oculta, se muestra esa pestaña al guardar.
	form.addEventListener('invalid', function (e) {
		var panel = e.target.closest('.qhatuq-tab');
		if (panel) { show(panel.dataset.tab); }
	}, true);

	var initial = (window.location.hash || '').replace('#', '');
	if (!initial) {
		try { initial = window.localStorage.getItem(STORE) || ''; } catch (e) { initial = ''; }
	}

	// ---------------------------------------------------------------- Vista previa
	var stage = document.getElementById('qhatuq-preview');
	var shadow = null;
	var ICONS = {
		chat: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3C6.5 3 2 6.8 2 11.5c0 2.4 1.2 4.6 3.1 6.1L4.3 21l4-1.9c1.2.4 2.4.6 3.7.6 5.5 0 10-3.8 10-8.4S17.5 3 12 3z" fill="currentColor"/></svg>',
		close: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>',
		minimize: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2.2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		send: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 12h13M12.5 6l6 6-6 6" stroke="currentColor" stroke-width="2.2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		lock: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10V8a5 5 0 0 1 10 0v2m-11 0h12v10H6z" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linejoin="round"/></svg>'
	};

	// Ajustes propios de la vista previa: el chat no es flotante y se ven a la vez abierto y cerrado.
	var PREVIEW_CSS =
		':host{display:block}' +
		'.preview-wrap{display:flex;flex-direction:column;gap:18px}' +
		'.preview-label{font:600 11px/1.2 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;text-transform:uppercase;letter-spacing:.06em;color:#646970;margin:0 0 6px}' +
		'.root{position:relative !important;bottom:auto !important;left:auto !important;right:auto !important;z-index:auto !important}' +
		'.root .panel{width:100% !important;height:auto !important;max-height:none !important;opacity:1 !important;visibility:visible !important;transform:none !important;transition:none !important;margin-bottom:0}' +
		'.root .log{flex:none !important;overflow:visible !important}' +
		'.root .launcher{cursor:default}' +
		'.closed-row{display:flex;flex-direction:column;gap:12px}' +
		'.root.left .closed-row{align-items:flex-start}' +
		'.root.right .closed-row{align-items:flex-end}' +
		'.root .teaser{opacity:1 !important;transform:none !important}';

	function val(name) {
		var els = form.querySelectorAll('[name="qhatuq_settings[' + name + ']"]');
		for (var i = 0; i < els.length; i++) {
			var el = els[i];
			if (el.type === 'radio' || el.type === 'checkbox') {
				if (el.checked) { return el.value; }
			} else {
				return el.value;
			}
		}
		return '';
	}

	function onColor(hex) {
		var m = /^#?([0-9a-f]{6})$/i.exec(hex || '');
		if (!m) { return '#ffffff'; }
		var n = parseInt(m[1], 16);
		var lin = [(n >> 16) & 255, (n >> 8) & 255, n & 255].map(function (v) {
			v /= 255;
			return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
		});
		return 0.2126 * lin[0] + 0.7152 * lin[1] + 0.0722 * lin[2] > 0.45 ? '#111827' : '#ffffff';
	}

	function esc(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function avatarUrl() {
		var wrap = document.querySelector('.qhatuq-avatar-preview');
		var img = wrap && wrap.querySelector('img');
		return wrap && wrap.style.display !== 'none' && img && img.getAttribute('src') ? img.getAttribute('src') : '';
	}

	function avatarHtml(size, url, initial) {
		return '<span class="avatar' + (size ? ' ' + size : '') + '">' +
			(url ? '<img src="' + esc(url) + '" alt="">' : esc(initial)) + '</span>';
	}

	function render() {
		if (!stage) { return; }
		if (!shadow) {
			shadow = stage.attachShadow ? stage.attachShadow({ mode: 'open' }) : stage;
			shadow.innerHTML =
				'<link rel="stylesheet" href="' + esc(cfg.cssUrl || '') + '">' +
				'<style>' + PREVIEW_CSS + '</style>' +
				'<div class="preview-wrap"></div>';
		}
		var theme = val('widget_theme') || 'clasico';
		var color = val('widget_color') || '#0b5cab';
		var position = val('widget_position') === 'left' ? 'left' : 'right';
		var agent = (document.getElementById('qhatuq-agent') || {}).value || 'Asistente';
		var title = val('widget_title') || 'Asesor comercial';
		var greeting = val('greeting') || '¡Hola! ¿En qué le puedo ayudar?';
		var notice = val('privacy_notice');
		var label = val('launcher_label');
		var icon = val('launcher_icon');
		var suggestions = (val('widget_suggestions') || '').split('\n').map(function (x) { return x.trim(); }).filter(Boolean).slice(0, 6);
		var url = avatarUrl();
		var initial = agent.trim().charAt(0).toUpperCase() || 'A';

		var code = document.getElementById('qhatuq-color-code');
		if (code) { code.textContent = color; }
		var site = document.getElementById('qhatuq-preview-site');
		if (site && cfg.homeUrl) { site.href = cfg.homeUrl + (cfg.homeUrl.indexOf('?') === -1 ? '?' : '&') + 'qhatuq_theme=' + encodeURIComponent(theme); }

		var vars = 'style="--accent:' + esc(color) + ';--on-accent:' + onColor(color) + '"';
		var launcherIco = icon === 'avatar'
			? '<span class="ico ico-chat ico-avatar">' + avatarHtml('', url, initial) + '<span class="launcher-dot"></span></span>'
			: '<span class="ico ico-chat">' + ICONS.chat + '</span>';
		var launcherCls = 'launcher' + (label ? ' has-label' : '') + (icon === 'avatar' ? ' with-avatar' : '');

		var openChat =
			'<div class="root theme-' + esc(theme) + ' ' + position + ' open" ' + vars + '>' +
				'<section class="panel">' +
					'<header class="header"><div class="who"><span class="avatar-wrap">' + avatarHtml('', url, initial) + '<span class="status-dot"></span></span>' +
					'<div class="titles"><strong>' + esc(title) + '</strong><span>' + esc(agent) + ' · En línea</span></div></div>' +
					'<button type="button" class="icon-btn" tabindex="-1">' + ICONS.minimize + '</button></header>' +
					'<div class="log">' +
						(notice ? '<p class="notice">' + ICONS.lock + esc(notice) + '</p>' : '') +
						'<div class="row assistant">' + avatarHtml('sm', url, initial) + '<div class="stack"><div class="bubble"><p>' + esc(greeting) + '</p></div></div></div>' +
						'<div class="row user"><div class="stack"><div class="bubble">Necesito una cotización</div><span class="time">10:24</span></div></div>' +
						'<div class="row assistant">' + avatarHtml('sm', url, initial) + '<div class="stack"><div class="bubble"><p>¡Con gusto! ¿Para qué producto o servicio y en qué cantidad?</p></div><span class="time">10:24</span></div></div>' +
					'</div>' +
					(suggestions.length ? '<div class="chips">' + suggestions.map(function (x) { return '<button type="button" class="chip" tabindex="-1">' + esc(x) + '</button>'; }).join('') + '</div>' : '') +
					'<div class="composer"><div class="field"><textarea class="input" rows="1" placeholder="Escriba su mensaje…" tabindex="-1" readonly></textarea><button type="button" class="send" disabled tabindex="-1">' + ICONS.send + '</button></div></div>' +
				'</section>' +
			'</div>';

		var closed =
			'<div class="root theme-' + esc(theme) + ' ' + position + '" ' + vars + '>' +
				'<div class="closed-row">' +
					'<div class="teaser show"><button type="button" class="teaser-text" tabindex="-1">' + avatarHtml('sm', url, initial) + '<span>' + esc(greeting) + '</span></button>' +
					'<button type="button" class="teaser-close" tabindex="-1">' + ICONS.close + '</button></div>' +
					'<button type="button" class="' + launcherCls + '" tabindex="-1">' + launcherIco + '<span class="ico ico-close">' + ICONS.close + '</span>' +
					(label ? '<span class="launcher-label">' + esc(label) + '</span>' : '') + '</button>' +
				'</div>' +
			'</div>';

		shadow.querySelector('.preview-wrap').innerHTML =
			'<div><p class="preview-label">Chat abierto</p>' + openChat + '</div>' +
			'<div><p class="preview-label">Chat cerrado (con invitación)</p>' + closed + '</div>';
	}

	var timer = null;
	function schedule() {
		clearTimeout(timer);
		timer = setTimeout(render, 80);
	}
	form.addEventListener('input', schedule);
	form.addEventListener('change', schedule);

	// La foto del agente se cambia con la Biblioteca de medios (sin eventos de formulario).
	var avatarField = document.querySelector('.qhatuq-avatar-field');
	if (avatarField && window.MutationObserver) {
		new MutationObserver(schedule).observe(avatarField, { attributes: true, subtree: true, attributeFilter: ['src', 'style'] });
	}

	show(initial || 'general');
	render();
})();
