/* Aurora — front-end auth, check-in and bookmark handlers. */
(function () {
  'use strict';

  var cfg = window.Aurora || {};

  function $(s, c) { return (c || document).querySelector(s); }
  function $$(s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); }

  function toast(msg) {
    var t = document.querySelector('.aurora-toast');
    if (!t) { t = document.createElement('div'); t.className = 'aurora-toast'; document.body.appendChild(t); }
    t.textContent = msg;
    t.classList.add('is-open');
    clearTimeout(t._t);
    t._t = setTimeout(function () { t.classList.remove('is-open'); }, 2200);
  }

  function post(action, data) {
    var body = new FormData();
    body.append('action', action);
    body.append('nonce', cfg.nonce || '');
    Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
    return fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }

  function setMsg(form, text, ok) {
    var el = $('.aurora-auth-form__msg', form) || form.querySelector('[role="status"]');
    if (!el) { return; }
    el.textContent = text || '';
    el.style.color = ok ? 'var(--aurora-success)' : 'var(--aurora-danger)';
    el.style.marginTop = '12px';
    el.style.fontSize = '.86rem';
    el.style.textAlign = 'center';
  }

  /* Login */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form.classList || !form.classList.contains('aurora-auth-form')) { return; }
    e.preventDefault();

    var mode = form.getAttribute('data-mode') || 'login';
    var btn = form.querySelector('button[type="submit"]');
    var data = {};
    new FormData(form).forEach(function (v, k) { data[k] = v; });

    if (btn) { btn.disabled = true; }
    setMsg(form, '处理中…', true);

    post(mode === 'register' ? 'aurora_register' : 'aurora_login', data).then(function (res) {
      if (res && res.success) {
        setMsg(form, res.data.message || '成功', true);
        setTimeout(function () { window.location.href = res.data.redirect || window.location.href; }, 500);
      } else {
        setMsg(form, (res && res.data && res.data.message) || '操作失败', false);
        if (btn) { btn.disabled = false; }
      }
    }).catch(function () {
      setMsg(form, '网络错误，请重试', false);
      if (btn) { btn.disabled = false; }
    });
  });

  /* Check-in */
  var checkin = $('#aurora-checkin');
  if (checkin) {
    checkin.addEventListener('click', function () {
      checkin.disabled = true;
      post('aurora_checkin', {}).then(function (res) {
        if (res && res.success) {
          toast(res.data.message);
          var pointEls = $$('.aurora-profile__stats b');
          if (pointEls.length >= 4) { pointEls[3].textContent = res.data.points; }
        } else {
          toast((res && res.data && res.data.message) || '签到失败');
          checkin.disabled = false;
        }
      }).catch(function () { toast('网络错误'); checkin.disabled = false; });
    });
  }

  /* Bookmark */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('.aurora-bookmark');
    if (!btn) { return; }
    e.preventDefault();
    btn.disabled = true;
    post('aurora_bookmark', { id: btn.getAttribute('data-id') }).then(function (res) {
      btn.disabled = false;
      if (res && res.success) {
        btn.classList.toggle('is-active', !!res.data.added);
        toast(res.data.message);
      } else {
        toast((res && res.data && res.data.message) || '操作失败');
      }
    }).catch(function () { btn.disabled = false; toast('网络错误'); });
  });

  /* Profile form */
  var profile = $('#aurora-profile-form');
  if (profile) {
    profile.addEventListener('submit', function (e) {
      e.preventDefault();
      var data = {};
      new FormData(profile).forEach(function (v, k) { data[k] = v; });
      var btn = profile.querySelector('button[type="submit"]');
      if (btn) { btn.disabled = true; }
      post('aurora_profile', data).then(function (res) {
        if (btn) { btn.disabled = false; }
        var msg = res && res.data && res.data.message ? res.data.message : '已保存';
        toast(msg);
        var nameEl = $('.aurora-profile__name');
        if (nameEl && data.display_name) {
          nameEl.childNodes[0].nodeValue = data.display_name + ' ';
        }
      }).catch(function () { if (btn) { btn.disabled = false; } toast('网络错误'); });
    });
  }
})();
