/* Universal Pay Box PRO — модальне вікно, копіювання логіна, миттєва активація */
(function () {
	'use strict';

	var D = window.UPB || {};
	var modal, els = {}, st = { sid: null, copied: false, busy: false, value: '' };

	function q(sel, root) { return (root || document).querySelector(sel); }

	function init() {
		modal = document.getElementById('upb-modal');
		if (modal) {
			els.svc  = q('[data-upb-svc]', modal);
			els.price = q('[data-upb-price]', modal);
			els.login = q('[data-upb-login]', modal);
			els.idlabel = q('[data-upb-idlabel]', modal);
			els.idword = q('[data-upb-idword]', modal);
			els.idword2 = q('[data-upb-idword2]', modal);
			els.copy = q('[data-upb-copy]', modal);
			els.go   = q('[data-upb-go]', modal);
			els.freemius = q('[data-upb-freemius]', modal);
			els.envelope = q('[data-upb-envelope]', modal);
			els.msg  = q('[data-upb-msg]', modal);
			els.body = q('[data-upb-body]', modal);
			els.copy.addEventListener('click', onCopy);
			els.go.addEventListener('click', onGo);
		}

		document.addEventListener('click', function (e) {
			var buy = e.target.closest ? e.target.closest('[data-upb-buy]') : null;
			if (buy) {
				e.preventDefault();
				openModal(buy.getAttribute('data-upb-buy'));
				return;
			}
			if (e.target.closest && e.target.closest('[data-upb-close]')) {
				closeModal();
				return;
			}
			var tx = e.target.closest ? e.target.closest('[data-upb-toast-close]') : null;
			if (tx) { tx.parentNode.style.display = 'none'; }
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { closeModal(); }
		});

		var toast = q('.upb-toast');
		if (toast) { setTimeout(function () { toast.style.display = 'none'; }, 15000); }
	}

	function showMsg(text, info) {
		els.msg.textContent = text;
		els.msg.className = 'upb-modal__msg' + (info ? ' is-info' : '');
		els.msg.hidden = false;
	}

	function openModal(sid) {
		if (!D.loggedIn) { window.location.href = D.loginUrl; return; }
		var svc = D.services && D.services[sid];
		if (!modal || !svc) { alert('Послугу не знайдено.'); return; }

		st.sid = sid; st.copied = false; st.busy = false;
		els.svc.textContent = svc.name;
		els.price.textContent = svc.price || '';
		var dom = svc.idMode === 'domain';
		st.value = dom ? D.domain : D.login;
		els.login.textContent = st.value;
		els.idlabel.textContent = dom ? 'Домен вашого сайту:' : 'Ваш логін на сайті:';
		els.idword.textContent = dom ? 'домен' : 'логін';
		els.idword2.textContent = dom ? 'домена' : 'логіна';
		st.label = dom ? '📋 Скопіювати домен' : '📋 Скопіювати логін';
		els.copy.textContent = st.label;
		els.copy.classList.remove('is-copied');
		els.go.disabled = true;
		els.go.hidden = !svc.url;
		els.go.style.display = svc.url ? '' : 'none';
		els.envelope.hidden = !svc.url;
		els.envelope.style.display = svc.url ? '' : 'none';
		els.go.textContent = 'Перейти до Конверта →';
		els.freemius.hidden = !svc.checkoutUrl;
		els.freemius.style.display = svc.checkoutUrl ? '' : 'none';
		if (svc.checkoutUrl) { els.freemius.href = svc.checkoutUrl; }
		els.msg.hidden = true;
		els.body.hidden = false;

		if (svc.state === 'blocked') {
			els.body.hidden = true;
			showMsg('Ваш доступ до цієї послуги заблоковано адміністратором. Зверніться до адміністрації сайту.');
		} else if (svc.state === 'active' || svc.state === 'unlimited') {
			els.body.hidden = true;
			showMsg('У вас уже активний тариф.', true);
		} else if (!svc.url && !svc.checkoutUrl) {
			els.body.hidden = true;
			showMsg('Для цієї послуги не налаштовано жодного способу оплати.');
		}

		modal.hidden = false;
		document.body.classList.add('upb-lock');
	}

	function closeModal() {
		if (!modal || modal.hidden || st.busy) { return; }
		modal.hidden = true;
		document.body.classList.remove('upb-lock');
	}

	function fallbackCopy(text) {
		try {
			var ta = document.createElement('textarea');
			ta.value = text;
			ta.setAttribute('readonly', '');
			ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;';
			document.body.appendChild(ta);
			ta.select();
			ta.setSelectionRange(0, text.length);
			var ok = document.execCommand('copy');
			document.body.removeChild(ta);
			return ok;
		} catch (err) { return false; }
	}

	function copyText(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return fallbackCopy(text); });
		}
		return Promise.resolve(fallbackCopy(text));
	}

	function selectLogin() {
		try {
			var r = document.createRange();
			r.selectNodeContents(els.login);
			var s = window.getSelection();
			s.removeAllRanges();
			s.addRange(r);
		} catch (err) { /* ігноруємо */ }
	}

	function onCopy() {
		copyText(st.value).then(function (ok) {
			st.copied = true;
			els.go.disabled = false;
			if (ok) {
				els.copy.textContent = 'Скопійовано! 👍';
				els.copy.classList.add('is-copied');
			} else {
				// Браузер заблокував копіювання — виділяємо логін, щоб скопіювати вручну.
				selectLogin();
				els.copy.textContent = 'Скопіюйте виділене вручну';
			}
		});
	}

	function onGo() {
		var svc = D.services[st.sid];
		if (!st.copied || st.busy || !svc || !svc.url) { return; }
		st.busy = true;
		els.go.disabled = true;
		els.go.textContent = 'Активація…';

		// Відкриваємо нову вкладку синхронно (інакше її заблокує браузер), URL підставимо після активації.
		var w = null;
		try { w = window.open('', '_blank'); } catch (err) { w = null; }
		if (w) {
			try { w.opener = null; w.document.title = 'Оплата'; w.document.body.innerHTML = '<p style="font-family:sans-serif;padding:20px">Зачекайте, переходимо до оплати…</p>'; } catch (err2) { /* ok */ }
		}

		var body = new URLSearchParams();
		body.append('action', 'upb_activate');
		body.append('nonce', D.nonce);
		body.append('service_id', st.sid);

		fetch(D.ajax, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res && res.success) {
					if (w) {
						w.location.href = res.data.url;
						setTimeout(function () { window.location.reload(); }, 200);
					} else {
						// Спливаюче вікно заблоковано — переходимо в цій же вкладці.
						window.location.href = res.data.url;
					}
				} else {
					fail(w, res && res.data && res.data.message);
				}
			})
			.catch(function () { fail(w, 'Помилка зʼєднання. Спробуйте ще раз.'); });
	}

	function fail(w, message) {
		if (w) { try { w.close(); } catch (e) { /* ok */ } }
		st.busy = false;
		els.go.disabled = false;
		els.go.textContent = 'Перейти до Конверта →';
		els.body.hidden = true;
		showMsg(message || 'Не вдалося активувати тариф. Спробуйте ще раз.');
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	/* Публічний JS API для ігор/додатків: UPB.open(serviceId) */
	window.UPB = window.UPB || {};
	window.UPB.open = openModal;
})();
