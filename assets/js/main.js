/* Aurora — front-end behaviour. Original work, no dependencies. */
(function () {
  'use strict';

  var cfg = window.Aurora || {};
  var i18n = cfg.i18n || {};
  var root = document.documentElement;

  /* ---------------------------------------------------------------------
     Utilities
     --------------------------------------------------------------------- */
  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

  function on(el, evt, fn, opts) { if (el) { el.addEventListener(evt, fn, opts || false); } }

  var toastEl = null;
  function toast(msg) {
    if (!toastEl) {
      toastEl = document.createElement('div');
      toastEl.className = 'aurora-toast';
      document.body.appendChild(toastEl);
    }
    toastEl.textContent = msg;
    toastEl.classList.add('is-open');
    clearTimeout(toastEl._t);
    toastEl._t = setTimeout(function () { toastEl.classList.remove('is-open'); }, 2000);
  }

  function throttle(fn, wait) {
    var last = 0, timer = null;
    return function () {
      var now = Date.now(), args = arguments, self = this;
      if (now - last >= wait) { last = now; fn.apply(self, args); }
      else {
        clearTimeout(timer);
        timer = setTimeout(function () { last = Date.now(); fn.apply(self, args); }, wait - (now - last));
      }
    };
  }

  function request(action, data) {
    var body = new FormData();
    body.append('action', action);
    body.append('nonce', cfg.nonce || '');
    Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
    return fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }

  /* ---------------------------------------------------------------------
     Colour scheme. Cycles the palettes the site enables, then "follow the
     system". The list arrives from Theme Settings, so hiding a palette
     never needs a code change.
     --------------------------------------------------------------------- */
  var STORE_KEY = 'aurora-theme';
  var PALETTES = (cfg.modes && cfg.modes.length) ? cfg.modes.slice() : ['light', 'dark'];
  var MODE_NAMES = cfg.modeNames || {};
  var MODE_SWITCH = cfg.modeSwitch || {};

  function isPalette(mode) { return PALETTES.indexOf(mode) !== -1; }
  function readStored() {
    try { return localStorage.getItem(STORE_KEY); } catch (e) { return null; }
  }
  function store(mode) {
    try { localStorage.setItem(STORE_KEY, mode); } catch (e) { /* storage disabled */ }
  }
  function systemDark() {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  }
  // The admin's pick for a first-time visitor. Anything stored wins over it,
  // including a stored "auto", so a visitor can escape a site-wide default
  // instead of being pushed back onto it on every page load.
  function defaultChoice() {
    var d = cfg.defaultMode;
    return (d === 'auto' || isPalette(d)) ? d : 'auto';
  }
  function currentChoice() {
    var s = readStored();
    if (s === 'auto' || isPalette(s)) { return s; }
    return defaultChoice();
  }
  function effectiveMode() {
    var c = currentChoice();
    return c === 'auto' ? (systemDark() ? 'dark' : 'light') : c;
  }
  function cycleOrder() { return PALETTES.concat(['auto']); }
  function modeName(mode) { return MODE_NAMES[mode] || mode; }

  function applyMode(choice) {
    if (isPalette(choice)) { root.setAttribute('data-theme', choice); }
    else { root.removeAttribute('data-theme'); choice = 'auto'; }

    var effective = (choice === 'auto') ? (systemDark() ? 'dark' : 'light') : choice;
    var order = cycleOrder();
    var pos = order.indexOf(currentChoice());
    var next = order[(pos + 1) % order.length];

    $$('.aurora-theme-toggle').forEach(function (btn) {
      // Two stops would be a pressed state; three or more is a cycle, so say
      // what the next click does instead of claiming the button is "on".
      btn.setAttribute('aria-label', MODE_SWITCH[next] || modeName(next));
      btn.setAttribute('title', MODE_SWITCH[next] || modeName(next));
      var sun = $('.aurora-icon--sun', btn);
      var moon = $('.aurora-icon--moon', btn);
      var wave = $('.aurora-icon--wave', btn);
      if (sun) { sun.style.display = effective === 'light' ? 'block' : 'none'; }
      if (moon) { moon.style.display = effective === 'dark' ? 'block' : 'none'; }
      if (wave) { wave.style.display = effective === 'spotify' ? 'block' : 'none'; }
    });
  }
  function cycleMode() {
    var order = cycleOrder();
    var next = order[(order.indexOf(currentChoice()) + 1) % order.length];
    store(next);
    applyMode(next);
    toast(modeName(next));
  }
  applyMode(currentChoice());

  if (window.matchMedia) {
    var mq = window.matchMedia('(prefers-color-scheme: dark)');
    var handler = function () { if (currentChoice() === 'auto') { applyMode('auto'); } };
    if (mq.addEventListener) { mq.addEventListener('change', handler); }
    else if (mq.addListener) { mq.addListener(handler); }
  }

  on(document, 'click', function (e) {
    var btn = e.target.closest && e.target.closest('.aurora-theme-toggle');
    if (btn) { e.preventDefault(); cycleMode(); }
  });

  /* ---------------------------------------------------------------------
     Header state, reading progress, scroll-to-top
     --------------------------------------------------------------------- */
  var header = $('.aurora-header');
  var progress = $('.aurora-progress');
  var toTop = $('.aurora-scrolltop');

  var onScroll = throttle(function () {
    var y = window.scrollY || window.pageYOffset;
    if (header) { header.classList.toggle('is-stuck', y > 6); }
    if (toTop) { toTop.classList.toggle('is-visible', y > 620); }

    if (progress) {
      var doc = document.documentElement;
      var max = doc.scrollHeight - window.innerHeight;
      progress.style.width = (max > 0 ? Math.min(100, (y / max) * 100) : 0) + '%';
    }
  }, 60);

  on(window, 'scroll', onScroll, { passive: true });
  on(window, 'resize', onScroll, { passive: true });
  onScroll();

  on(toTop, 'click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });

  /* 滚到页脚就把「回到顶部」收起来：它固定停在右下角，会压住看板娘 */
  var foot = $('.aurora-footer');
  if (foot && toTop) {
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (entries) {
        document.body.classList.toggle('aurora-at-footer', entries[0].isIntersecting);
      }, { rootMargin: '0px 0px -40px 0px' }).observe(foot);
    } else {
      on(window, 'scroll', throttle(function () {
        var r = foot.getBoundingClientRect();
        document.body.classList.toggle('aurora-at-footer', r.top < window.innerHeight - 40);
      }, 120), { passive: true });
    }
  }

  /* ---------------------------------------------------------------------
     Mobile navigation
     --------------------------------------------------------------------- */
  var navToggle = $('.aurora-mobile-toggle');
  var nav = $('.aurora-nav');
  on(navToggle, 'click', function () {
    var open = nav.classList.toggle('is-open');
    navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.classList.toggle('aurora-locked', open);
  });

  /* ---------------------------------------------------------------------
     Search overlay (AJAX against the REST API)
     --------------------------------------------------------------------- */
  var overlay = $('.aurora-searchbox');
  var sInput = overlay ? $('input[type="search"]', overlay) : null;
  var sResults = overlay ? $('.aurora-searchbox__results', overlay) : null;
  var sWords = overlay ? $('.aurora-searchbox__words', overlay) : null;
  var sTimer = null;
  var wTimer = null;

  /* Keyword chips: what the site is about when the box is empty, and matching
     terms while typing. They come from the site's own tags, so no request
     leaves the server. */
  function renderWords(words) {
    if (!sWords) { return; }
    if (!words || !words.length) { sWords.innerHTML = ''; sWords.hidden = true; return; }
    sWords.hidden = false;
    sWords.innerHTML = words.map(function (w) {
      var label = String(w.label).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
      return '<button type="button" class="aurora-word" data-term="' + label + '">' +
        '<span>' + label + '</span><small>' + (w.count || 0) + '</small></button>';
    }).join('');
  }

  function loadWords(term) {
    request('aurora_suggest', { term: term || '' })
      .then(function (res) { if (res && res.success) { renderWords(res.data.words); } })
      .catch(function () { renderWords([]); });
  }

  function openSearch() {
    if (!overlay) { return; }
    overlay.classList.add('is-open');
    document.body.classList.add('aurora-locked');
    setTimeout(function () {
      if (!sInput) { return; }
      sInput.focus();
      if (!sInput.value.trim()) { loadWords(''); }
    }, 60);
  }
  function closeSearch() {
    if (!overlay) { return; }
    overlay.classList.remove('is-open');
    document.body.classList.remove('aurora-locked');
  }

  on(document, 'click', function (e) {
    if (e.target.closest && e.target.closest('.aurora-search-trigger')) { e.preventDefault(); openSearch(); }
    if (e.target === overlay || (e.target.closest && e.target.closest('.aurora-search-close'))) { closeSearch(); }
  });

  on(document, 'keydown', function (e) {
    var isField = ['INPUT', 'TEXTAREA', 'SELECT'].indexOf(document.activeElement.tagName) > -1;
    if (e.key === '/' && !isField) { e.preventDefault(); openSearch(); }
    if (e.key === 'k' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); openSearch(); }
    if (e.key === 'Escape') { closeSearch(); }
  });

  function renderResults(items, term) {
    if (!sResults) { return; }
    if (!items.length) {
      sResults.innerHTML = '<div class="aurora-searchbox__empty">' + (i18n.noResult || 'No results') + '</div>';
      return;
    }
    sResults.innerHTML = items.map(function (p) {
      var thumb = p.thumb ? '<img src="' + p.thumb + '" alt="" loading="lazy">' : '<img alt="" src="data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\'/%3E">';
      return '<a class="aurora-searchbox__item" href="' + p.link + '">' + thumb +
        '<div><h5>' + p.title + '</h5><p>' + (p.excerpt || '') + '</p></div></a>';
    }).join('');
  }

  on(sInput, 'input', function () {
    var term = sInput.value.trim();
    clearTimeout(sTimer);
    clearTimeout(wTimer);

    if (term.length < 1) {
      if (sResults) { sResults.innerHTML = ''; }
      loadWords('');
      return;
    }

    wTimer = setTimeout(function () { loadWords(term); }, 180);

    if (term.length < 2) {
      if (sResults) { sResults.innerHTML = ''; }
      return;
    }
    if (sResults) { sResults.innerHTML = '<div class="aurora-searchbox__empty">' + (i18n.searching || 'Searching…') + '</div>'; }
    sTimer = setTimeout(function () {
      request('aurora_search', { term: term })
        .then(function (res) { if (res && res.success) { renderResults(res.data, term); } })
        .catch(function () { renderResults([], term); });
    }, 260);
  });

  on(sWords, 'click', function (e) {
    var chip = e.target.closest ? e.target.closest('.aurora-word') : null;
    if (!chip || !sInput) { return; }
    sInput.value = chip.getAttribute('data-term') || '';
    sInput.dispatchEvent(new Event('input', { bubbles: true }));
    sInput.focus();
  });

  on(overlay, 'submit', function (e) { e.preventDefault(); });

  /* ---------------------------------------------------------------------
     Reveal on scroll
     --------------------------------------------------------------------- */
  var reveals = $$('.aurora-reveal');
  if (reveals.length) {
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-in');
            io.unobserve(entry.target);
          }
        });
      }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });
      reveals.forEach(function (el) { io.observe(el); });
    } else {
      reveals.forEach(function (el) { el.classList.add('is-in'); });
    }
  }

  /* ---------------------------------------------------------------------
     Table of contents + active heading tracking
     --------------------------------------------------------------------- */
  var article = $('.aurora-content');
  var toc = $('.aurora-toc');
  var headless = false;

  if (article && toc) {
    var headings = $$('h2, h3', article).filter(function (h) {
      return (h.textContent || '').trim().length > 1;
    });

    if (headings.length < 2) {
      headless = true;
      var rail = toc.closest('.aurora-toc-wrap') || toc.parentNode;
      if (rail) { rail.style.display = 'none'; }
    } else {
      var list = document.createElement('ul');
      headings.forEach(function (h, i) {
        if (!h.id) {
          h.id = 'section-' + i + '-' + (h.textContent || '').trim().slice(0, 22)
            .replace(/[^\w\u4e00-\u9fa5-]+/g, '-').replace(/^-+|-+$/g, '').toLowerCase();
        }
        var li = document.createElement('li');
        if (h.tagName === 'H3') { li.className = 'aurora-toc__l3'; }
        var a = document.createElement('a');
        a.href = '#' + h.id;
        a.textContent = (h.textContent || '').trim();
        a.dataset.target = h.id;
        li.appendChild(a);
        list.appendChild(li);
      });
      toc.appendChild(list);

      var links = $$('a', toc);
      var spy = throttle(function () {
        var offset = 140, current = null;
        headings.forEach(function (h) {
          if (h.getBoundingClientRect().top <= offset) { current = h.id; }
        });
        links.forEach(function (a) { a.classList.toggle('is-active', a.dataset.target === current); });
      }, 90);
      on(window, 'scroll', spy, { passive: true });
      spy();

      on(toc, 'click', function (e) {
        var a = e.target.closest && e.target.closest('a');
        if (!a) { return; }
        var target = document.getElementById(a.dataset.target);
        if (!target) { return; }
        e.preventDefault();
        var top = target.getBoundingClientRect().top + (window.scrollY || window.pageYOffset) - 84;
        window.scrollTo({ top: top, behavior: 'smooth' });
        history.replaceState(null, '', '#' + a.dataset.target);
      });
    }
  }

  /* ---------------------------------------------------------------------
     Lightbox — article images and the gallery
     --------------------------------------------------------------------- */
  var lightbox = $('.aurora-lightbox');
  if (lightbox) {
    var lbImg = $('img', lightbox);
    var lbCap = $('.aurora-lightbox__caption', lightbox);
    var lbItems = [];
    var lbIndex = 0;

    // Everything the viewer can step through: gallery tiles, then the article's
    // own images. Tiles are lazy and not measured yet, so the small-image guard
    // only applies to content images.
    var lbCollect = function () {
      var out = $$('.aurora-gallery__item img');
      if (article) {
        $$('img', article).forEach(function (img) {
          if (img.closest('.aurora-paywall') || img.width < 240) { return; }
          out.push(img);
        });
      }
      return out;
    };

    var lbShow = function (i) {
      lbItems = lbCollect();
      if (!lbItems.length) { return; }
      lbIndex = (i + lbItems.length) % lbItems.length;

      var img = lbItems[lbIndex];
      lbImg.src = img.getAttribute('data-full') || img.currentSrc || img.src;
      lbImg.alt = img.getAttribute('alt') || '';

      if (!lbCap) { return; }
      lbCap.textContent = '';
      var caption = img.getAttribute('data-caption') || img.getAttribute('alt') || '';
      var post = img.getAttribute('data-post');
      if (caption) { lbCap.appendChild(document.createTextNode(caption)); }
      if (post) {
        var link = document.createElement('a');
        link.href = post;
        link.textContent = (caption ? ' · ' : '') + (i18n.viewPost || '查看文章');
        lbCap.appendChild(link);
      }
      if (lbItems.length > 1) {
        // The grid publishes the library total; the header counts the same
        // images, so the counter has to as well instead of reporting the
        // size of the current page.
        var grid = $('.aurora-gallery');
        var total = grid ? parseInt(grid.getAttribute('data-count'), 10) || 0 : 0;
        lbCap.appendChild(document.createTextNode(
          (lbCap.childNodes.length ? '　' : '') + (lbIndex + 1) + ' / ' + Math.max(total, lbItems.length)
        ));
      }
      lbCap.hidden = !lbCap.textContent;
    };

    var lbClose = function () {
      lightbox.classList.remove('is-open');
      document.body.classList.remove('aurora-locked');
    };

    // Stepping past the end of the loaded set pulls the next page in, so the
    // viewer walks the whole library instead of stopping at the page edge.
    var lbPending = 0;

    var lbStep = function (dir) {
      if (lbItems.length < 2) { return; }
      var want = lbIndex + dir;
      if (want >= lbItems.length) {
        var more = $('.aurora-loadmore button[data-next]');
        if (more && !more.disabled) {
          lbPending = dir;
          more.click();
          return;
        }
        want = 0;                       // everything is loaded: wrap around
      } else if (want < 0) {
        want = lbItems.length - 1;
      }
      lbShow(want);
    };

    on(document, 'aurora:appended', function () {
      if (!lbPending) { return; }
      var dir = lbPending;
      lbPending = 0;
      lbShow(lbIndex + dir);
    });

    on(document, 'click', function (e) {
      // Matches exactly what lbCollect() gathers: gallery tiles and the images
      // inside the entry content. Matching .aurora-article here would also catch
      // the featured-image hero, which the viewer does not step through.
      var img = e.target.closest && e.target.closest('.aurora-gallery__item img, .aurora-content img');
      if (!img || img.closest('.aurora-paywall')) { return; }
      if (!img.closest('.aurora-gallery__item') && img.width < 240) { return; }

      var all = lbCollect();
      var at = all.indexOf(img);
      if (at < 0) { return; }

      e.preventDefault();
      lbItems = all;
      lbShow(at);
      lightbox.classList.add('is-open');
      document.body.classList.add('aurora-locked');
    });

    $$('.aurora-lightbox__nav', lightbox).forEach(function (btn) {
      on(btn, 'click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        lbStep(btn.getAttribute('data-dir') === 'prev' ? -1 : 1);
      });
    });

    on(lightbox, 'click', function (e) {
      // Clicking the picture or the backdrop closes, as it always has; the
      // controls stop propagation and a caption link must stay clickable.
      if (e.target.closest && e.target.closest('.aurora-lightbox__caption a')) { return; }
      lbClose();
    });

    on(document, 'keydown', function (e) {
      if (!lightbox.classList.contains('is-open')) { return; }
      if (e.key === 'Escape') { lbClose(); }
      else if (e.key === 'ArrowLeft') { lbStep(-1); }
      else if (e.key === 'ArrowRight') { lbStep(1); }
    });
  }

  /* ---------------------------------------------------------------------
     Copy-to-clipboard buttons
     --------------------------------------------------------------------- */
  on(document, 'click', function (e) {
    var btn = e.target.closest && e.target.closest('.aurora-copy');
    if (!btn) { return; }
    var text = btn.getAttribute('data-clipboard') || '';
    var done = function () {
      toast(i18n.copied || 'Copied');
      var old = btn.getAttribute('data-label');
      if (old) { btn.setAttribute('data-label', ''); setTimeout(function () { btn.setAttribute('data-label', old); }, 1600); }
    };
    if (navigator.clipboard) { navigator.clipboard.writeText(text).then(done, done); }
    else {
      var ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); done(); } catch (err) { /* ignore */ }
      document.body.removeChild(ta);
    }
  });

  /* ---------------------------------------------------------------------
     Like / favourite
     --------------------------------------------------------------------- */
  on(document, 'click', function (e) {
    var btn = e.target.closest && e.target.closest('.aurora-like');
    if (!btn) { return; }
    e.preventDefault();
    var id = btn.getAttribute('data-id');
    var was = btn.classList.contains('is-active');
    btn.classList.toggle('is-active', !was);
    var counter = $('.aurora-like-count', btn);
    if (counter) {
      var n = parseInt(counter.textContent, 10) || 0;
      counter.textContent = Math.max(0, n + (was ? -1 : 1));
    }
    request('aurora_like', { id: id }).then(function (res) {
      if (res && res.success && counter) { counter.textContent = res.data.count; }
    }).catch(function () {
      btn.classList.toggle('is-active', was);
      if (counter) { counter.textContent = Math.max(0, (parseInt(counter.textContent, 10) || 0) + (was ? 1 : -1)); }
    });
  });

  /* ---------------------------------------------------------------------
     Tabs
     --------------------------------------------------------------------- */
  on(document, 'click', function (e) {
    var tab = e.target.closest && e.target.closest('.aurora-tab');
    if (!tab) { return; }
    e.preventDefault();
    var group = tab.closest('.aurora-tabs');
    var name = tab.getAttribute('data-tab');
    $$('.aurora-tab', group).forEach(function (t) {
      var active = t === tab;
      t.classList.toggle('is-active', active);
      t.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    var scope = group.getAttribute('data-scope');
    $$('.aurora-tabpane[data-pane]').forEach(function (pane) {
      if (scope && pane.getAttribute('data-scope') !== scope) { return; }
      pane.classList.toggle('is-active', pane.getAttribute('data-pane') === name);
    });
    if (history.replaceState) { history.replaceState(null, '', '#' + name); }
  });

  /* ---------------------------------------------------------------------
     "Load more" pagination
     --------------------------------------------------------------------- */
  on(document, 'click', function (e) {
    var btn = e.target.closest && e.target.closest('.aurora-loadmore button');
    if (!btn) { return; }
    e.preventDefault();
    var next = btn.getAttribute('data-next');
    if (!next) { return; }
    var target = $(btn.getAttribute('data-target') || '#aurora-loop');
    if (!target) { return; }
    btn.classList.add('is-loading');
    btn.textContent = i18n.loading || 'Loading…';

    fetch(next, { credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var items = doc.querySelectorAll((btn.getAttribute('data-item') || '.aurora-grid > *'));
        if (!items.length) {
          btn.textContent = i18n.noMore || 'No more';
          btn.disabled = true;
          return;
        }
        Array.prototype.forEach.call(items, function (node) { target.appendChild(node); });
        var nextLink = doc.querySelector('link[rel="next"]');
        if (nextLink) { btn.setAttribute('data-next', nextLink.getAttribute('href')); btn.textContent = i18n.more || 'Load more'; }
        else { btn.textContent = i18n.noMore || 'No more'; btn.disabled = true; }
        btn.classList.remove('is-loading');
        // The lightbox listens for this so it can advance past the page edge.
        document.dispatchEvent(new CustomEvent('aurora:appended', { detail: { target: target, added: items.length } }));
      })
      .catch(function () {
        btn.classList.remove('is-loading');
        btn.textContent = i18n.more || 'Load more';
        toast('加载失败，请重试');
      });
  });

  /* ---------------------------------------------------------------------
     Modal open/close
     --------------------------------------------------------------------- */
  on(document, 'click', function (e) {
    var opener = e.target.closest && e.target.closest('[data-aurora-modal]');
    if (opener) {
      e.preventDefault();
      var modal = document.getElementById(opener.getAttribute('data-aurora-modal'));
      if (modal) {
        modal.classList.add('is-open');
        document.body.classList.add('aurora-locked');
        var first = $('input,button,select,textarea', modal);
        if (first) { setTimeout(function () { first.focus(); }, 80); }
      }
      return;
    }
    var closer = e.target.closest && e.target.closest('[data-aurora-close]');
    if (closer) {
      e.preventDefault();
      var box = closer.closest('.aurora-modal');
      if (box) { box.classList.remove('is-open'); document.body.classList.remove('aurora-locked'); }
      return;
    }
    if (e.target.classList && e.target.classList.contains('aurora-modal')) {
      e.target.classList.remove('is-open');
      document.body.classList.remove('aurora-locked');
    }
  });

  /* ---------------------------------------------------------------------
     Announcement dismissal
     --------------------------------------------------------------------- */
  on(document, 'click', function (e) {
    var btn = e.target.closest && e.target.closest('.aurora-notice__dismiss');
    if (!btn) { return; }
    var bar = btn.closest('.aurora-notice');
    var key = btn.getAttribute('data-key') || 'aurora-notice';
    try { localStorage.setItem(key, '1'); } catch (err) { /* ignore */ }
    if (bar) { bar.style.display = 'none'; }
  });

  /* ---------------------------------------------------------------------
     AJAX comment submission
     --------------------------------------------------------------------- */
  var commentForm = $('#commentform');
  on(commentForm, 'submit', function (e) {
    e.preventDefault();
    var submit = $('input[type="submit"], button[type="submit"]', commentForm);
    var list = $('#commentlist') || $('.aurora-comments ol');
    if (submit) { submit.disabled = true; }
    fetch(commentForm.getAttribute('action'), {
      method: 'POST',
      body: new FormData(commentForm),
      credentials: 'same-origin'
    })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var incoming = doc.querySelector('#commentlist > li, .aurora-comments ol > li');
        if (incoming && list) {
          var node = document.importNode(incoming, true);
          list.appendChild(node);
          toast('评论已发布');
          commentForm.reset();
        } else if (doc.querySelector('.wp-die-message, .comment-form-error')) {
          window.location.href = commentForm.getAttribute('action') + '?aurora_error=1';
          return;
        } else {
          list.appendChild(document.importNode(doc.body, true));
        }
      })
      .catch(function () { commentForm.submit(); })
      .finally(function () { if (submit) { submit.disabled = false; } });
  });

  /* ---------------------------------------------------------------------
     Trending topic tabs
     --------------------------------------------------------------------- */
  on(document, 'click', function (e) {
    var tab = e.target.closest && e.target.closest('.aurora-hot__tab');
    if (!tab) { return; }
    e.preventDefault();

    var card = tab.closest('[data-hot]') || document;
    var key = tab.getAttribute('data-hot-tab');

    $$('.aurora-hot__tab', card).forEach(function (t) {
      var active = t === tab;
      t.classList.toggle('is-active', active);
      t.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    $$('.aurora-hot__list', card).forEach(function (list) {
      list.classList.toggle('is-active', list.getAttribute('data-hot-pane') === key);
    });
  });

  /* ---------------------------------------------------------------------
     Link directory: instant search + category chips
     --------------------------------------------------------------------- */
  (function () {
    var grid = document.getElementById('aurora-navgrid');
    if (!grid) { return; }

    var input = document.getElementById('aurora-nav-search');
    var cards = $$('.aurora-link-card', grid);
    var groups = $$('.aurora-navgroup', grid);
    var chips = $$('[data-nav-filter]');
    var empty = document.getElementById('aurora-navempty');
    var activeCat = 'all';

    function apply() {
      var term = (input && input.value ? input.value : '').trim().toLowerCase();
      var shown = 0;

      groups.forEach(function (group) {
        var groupMatch = activeCat === 'all' || group.getAttribute('data-group') === activeCat;
        var groupShown = 0;

        $$('.aurora-link-card', group).forEach(function (card) {
          var haystack = (card.getAttribute('data-name') || '').toLowerCase();
          var visible = groupMatch && (!term || haystack.indexOf(term) > -1);
          card.hidden = !visible;
          if (visible) { groupShown++; }
        });

        group.hidden = groupShown === 0;
        shown += groupShown;
      });

      if (empty) { empty.hidden = shown > 0; }
    }

    on(input, 'input', apply);

    chips.forEach(function (chip) {
      on(chip, 'click', function () {
        chips.forEach(function (c) { c.classList.toggle('is-active', c === chip); });
        activeCat = chip.getAttribute('data-nav-filter') || 'all';
        apply();
      });
    });
  })();

  /* ---------------------------------------------------------------------
     WeChat Pay: poll the order until it settles
     --------------------------------------------------------------------- */
  (function () {
    var panel = document.querySelector('.aurora-wxpay');
    if (!panel) { return; }

    var orderId = panel.getAttribute('data-order');
    var status = $('.aurora-wxpay__status', panel);
    var statusText = status ? $('span', status) : null;
    var timer = null;

    function refresh(manual) {
      if (!orderId) { return; }
      request('aurora_wxpay_status', { order: orderId }).then(function (res) {
        if (!res || !res.success) { return; }
        if (res.data.status === 'paid') {
          if (status) { status.classList.add('is-paid'); }
          if (statusText) { statusText.textContent = res.data.paidText || '已支付'; }
          clearInterval(timer);
          setTimeout(function () { window.location.reload(); }, 1400);
        } else if (manual && statusText) {
          statusText.textContent = (i18n.loading || 'Loading…');
          setTimeout(function () { statusText.textContent = '等待支付…'; }, 1200);
        }
      });
    }

    // Poll every 6s for two minutes, then stop: the callback will settle it anyway.
    var rounds = 0;
    timer = setInterval(function () {
      rounds++;
      refresh(false);
      if (rounds > 20) { clearInterval(timer); }
    }, 6000);

    on($('.aurora-wxpay__refresh', panel), 'click', function () { refresh(true); });
  })();

  /* ---------------------------------------------------------------------
     Coupon box
     --------------------------------------------------------------------- */
  (function () {
    $$('.aurora-couponbox').forEach(function (box) {
      var input = $('.aurora-couponbox__input', box);
      var btn = $('.aurora-couponbox__btn', box);
      var msg = $('.aurora-couponbox__msg', box);
      var form = box.closest('form');

      if (!input || !btn) { return; }

      function say(text, ok) {
        if (!msg) { return; }
        msg.textContent = text || '';
        msg.classList.toggle('is-ok', !!ok);
        msg.classList.toggle('is-error', !ok && !!text);
      }

      function apply() {
        var code = (input.value || '').trim();
        if (!code) { say('', true); return; }

        btn.disabled = true;
        say(i18n.loading || 'Loading…', true);

        var payload = {
          code: code,
          scope: box.getAttribute('data-scope') || 'vip',
          object_id: box.getAttribute('data-object') || 0,
        };

        // Membership pricing depends on the chosen term, so mirror it.
        if (form) {
          var level = $('input[name="level"]', form);
          var days = $('select[name="days"]', form);
          if (level) { payload.level = level.value; }
          if (days) { payload.days = days.value; }
        }

        request('aurora_coupon', payload).then(function (res) {
          btn.disabled = false;
          if (res && res.success && res.data) {
            say(res.data.message || res.data.label, true);
            input.classList.add('is-valid');
            box.setAttribute('data-discount', res.data.discount);
            box.setAttribute('data-total', res.data.total);
            updateTotals(box, res.data);
          } else {
            input.classList.remove('is-valid');
            box.removeAttribute('data-discount');
            box.removeAttribute('data-total');
            updateTotals(box, null);
            say((res && res.data && res.data.message) || '优惠码不可用', false);
          }
        });
      }

      // Show the discounted total next to the button when we can.
      function updateTotals(box, data) {
        if (!form) { return; }
        var submit = $('button[type="submit"]', form);
        if (!submit) { return; }

        if (!submit.dataset.originalLabel) {
          submit.dataset.originalLabel = submit.textContent.trim();
        }
        if (data && data.total !== undefined) {
          submit.textContent = submit.dataset.originalLabel + ' · ¥' + Number(data.total).toFixed(2);
        } else {
          submit.textContent = submit.dataset.originalLabel;
        }
      }

      on(btn, 'click', apply);
      on(input, 'keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); apply(); }
      });
      // Re-validate when the term changes, since the threshold may no longer hold.
      if (form) {
        on($('select[name="days"]', form), 'change', function () {
          if (box.getAttribute('data-discount')) { apply(); }
        });
      }
    });
  })();

  /* ---------------------------------------------------------------------
     Notifications
     --------------------------------------------------------------------- */
  (function () {
    var bell = $('#aurora-bell');
    if (!bell) { return; }

    var btn   = $('.aurora-bell__btn', bell);
    var panel = $('#aurora-bell-panel', bell);
    var list  = $('#aurora-bell-list', bell);
    var dot   = $('#aurora-bell-dot');
    var count = $('#aurora-bell-unread', bell);
    var loaded = false;

    function el(tag, cls, text) {
      var e = document.createElement(tag);
      if (cls) { e.className = cls; }
      if (text != null) { e.textContent = text; }
      return e;
    }

    function setDot(n) {
      if (!dot) { return; }
      if (n > 0) {
        dot.hidden = false;
        dot.textContent = n > 99 ? '99+' : String(n);
      } else {
        dot.hidden = true;
      }
      if (count) {
        count.textContent = n > 0 ? '(' + n + ')' : '';
      }
    }

    // Built node by node rather than with innerHTML: notification bodies
    // contain user-controlled text (comment excerpts, order numbers).
    function render(items) {
      list.textContent = '';
      if (!items || !items.length) {
        list.appendChild(el('p', 'aurora-bell__empty', i18n.noMessages || '暂无消息'));
        return;
      }

      items.forEach(function (it) {
        var row = document.createElement(it.link ? 'a' : 'div');
        row.className = 'aurora-bell__item' + (it.read ? ' is-read' : '');
        if (it.link) { row.href = it.link; }

        row.appendChild(el('span', 'aurora-bell__item-title', it.title));
        if (it.body) { row.appendChild(el('span', 'aurora-bell__item-body', it.body)); }
        row.appendChild(el('span', 'aurora-bell__item-time', it.created));

        if (!it.read) {
          row.addEventListener('click', function () {
            request('aurora_notify_read', { id: it.id }).then(function () {
              row.classList.add('is-read');
            });
          });
        }

        list.appendChild(row);
      });
    }

    function load() {
      list.textContent = '';
      list.appendChild(el('p', 'aurora-bell__empty', i18n.loading || 'Loading…'));

      request('aurora_notify_fetch', {}).then(function (res) {
        if (!res || !res.success) { return; }
        loaded = true;
        setDot(res.data.unread || 0);
        render(res.data.items || []);
      });
    }

    on(btn, 'click', function (e) {
      e.preventDefault();
      var open = panel.hidden;
      panel.hidden = !open;
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { load(); }
    });

    on(document, 'click', function (e) {
      if (!bell.contains(e.target) && !panel.hidden) {
        panel.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
      }
    });

    on(document, 'keydown', function (e) {
      if (e.key === 'Escape' && !panel.hidden) {
        panel.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
        btn.focus();
      }
    });

    on($('#aurora-bell-read'), 'click', function (e) {
      e.preventDefault();
      request('aurora_notify_read', {}).then(function (res) {
        if (!res || !res.success) { return; }
        setDot(res.data.unread || 0);
        if (loaded) { load(); }
        toast(i18n.markedRead || '已标为已读');
      });
    });
  })();

  /* ---------------------------------------------------------------------
     Inbox (user centre)
     --------------------------------------------------------------------- */
  (function () {
    var btn = $('#aurora-notify-read-all');
    if (!btn) { return; }

    var msg = $('.aurora-auth-form__msg', btn.parentNode);

    on(btn, 'click', function () {
      btn.disabled = true;
      request('aurora_notify_read', {}).then(function (res) {
        btn.disabled = false;
        if (msg) { msg.textContent = (res && res.success) ? (i18n.markedRead || '已标为已读') : ''; }
        if (!res || !res.success) { return; }

        $$('#aurora-notify-list .aurora-notify-item').forEach(function (li) {
          li.classList.remove('is-unread');
          li.classList.add('is-read');
        });

        var badge = $('#aurora-notify-count');
        if (badge) { badge.hidden = true; }
        var tabBadge = $('.aurora-tab__badge');
        if (tabBadge) { tabBadge.hidden = true; }
      });
    });
  })();

  /* ---------------------------------------------------------------------
     Redeem a card
     --------------------------------------------------------------------- */
  (function () {
    var form = $('#aurora-redeem-form');
    if (!form) { return; }

    var input = $('#aurora-card-code', form);
    var msg   = $('.aurora-auth-form__msg', form);
    var submit = $('button[type="submit"]', form);

    // People paste codes with spaces and lowercase; the server normalises too,
    // but cleaning it here means the visible value matches what is sent.
    on(input, 'input', function () {
      var caret = input.selectionStart;
      input.value = input.value.toUpperCase();
      if (caret != null) { input.setSelectionRange(caret, caret); }
    });

    on(form, 'submit', function (e) {
      e.preventDefault();

      var code = (input.value || '').trim();
      if (!code) { return; }

      submit.disabled = true;
      if (msg) { msg.textContent = i18n.loading || 'Loading…'; }

      request('aurora_redeem', { code: code }).then(function (res) {
        submit.disabled = false;

        if (!res || !res.success) {
          if (msg) { msg.textContent = (res && res.data && res.data.message) || ''; }
          return;
        }

        if (msg) { msg.textContent = res.data.message || ''; }
        toast(res.data.message || '');

        // Reload so the new entitlement shows in the balance and the history.
        setTimeout(function () { window.location.reload(); }, 900);
      });
    });
  })();

  /* ---------------------------------------------------------------------
     Unlock a download with points
     --------------------------------------------------------------------- */
  (function () {
    $$('.aurora-downloads__unlock').forEach(function (box) {
      var btn = $('.aurora-downloads__buy', box);
      var msg = $('.aurora-auth-form__msg', box);
      if (!btn) { return; }

      on(btn, 'click', function () {
        var postId = box.getAttribute('data-post');
        if (!postId) { return; }

        btn.disabled = true;

        request('aurora_download_unlock', { post_id: postId }).then(function (res) {
          if (!res || !res.success) {
            btn.disabled = false;
            if (msg) { msg.textContent = (res && res.data && res.data.message) || ''; }
            return;
          }

          toast((res.data && res.data.message) || '');

          // Swap the lock for the file list without a reload.
          var list = document.createElement('ul');
          list.className = 'aurora-downloads__list';

          (res.data.files || []).forEach(function (f) {
            var li = document.createElement('li');
            li.className = 'aurora-downloads__item';

            var name = document.createElement('span');
            name.className = 'aurora-downloads__name';
            name.textContent = f.name;

            var size = document.createElement('span');
            size.className = 'aurora-downloads__size';
            size.textContent = f.size;

            var a = document.createElement('a');
            a.className = 'aurora-btn aurora-btn--primary aurora-btn--sm';
            a.href = f.url;
            a.rel = 'nofollow';
            a.textContent = i18n.download || 'Download';

            li.appendChild(name);
            li.appendChild(size);
            li.appendChild(a);
            list.appendChild(li);
          });

          box.parentNode.replaceChild(list, box);
        });
      });
    });
  })();

  /* ---------------------------------------------------------------------
     Misc
     --------------------------------------------------------------------- */
  $$('a[href^="http"]').forEach(function (a) {
    if (a.hostname && a.hostname !== window.location.hostname) {
      a.setAttribute('rel', 'noopener');
    }
  });

  if (!cfg.loggedIn) {
    $$('.aurora-need-login').forEach(function (el) {
      on(el, 'click', function (e) {
        e.preventDefault();
        toast(i18n.loginFirst || 'Please sign in');
        window.location.href = (cfg.loginUrl || '/wp-login.php') + '?redirect_to=' + encodeURIComponent(window.location.href);
      });
    });
  }

  /* ---------------------------------------------------------------------
     Reading extras: image lightbox, TOC scroll state, author follow,
     WeChat share hint
     --------------------------------------------------------------------- */
  (function lightbox() {
    var body = $('.aurora-content');
    if (!body) { return; }
    var images = $$('img', body).filter(function (img) {
      if (img.closest('.aurora-emoji')) { return false; }
      var w = img.naturalWidth || img.width || 0;
      return w === 0 || w > 120;
    });
    if (!images.length) { return; }

    var box = document.createElement('div');
    box.className = 'aurora-lightbox';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.innerHTML = '<button type="button" class="aurora-lightbox__close" aria-label="Close">&times;</button>'
      + '<button type="button" class="aurora-lightbox__nav aurora-lightbox__prev" aria-label="Previous">&#8249;</button>'
      + '<img alt="">'
      + '<button type="button" class="aurora-lightbox__nav aurora-lightbox__next" aria-label="Next">&#8250;</button>';
    document.body.appendChild(box);

    var big = box.querySelector('img');
    var idx = 0;

    function show(i) {
      idx = (i + images.length) % images.length;
      big.src = images[idx].getAttribute('data-full') || images[idx].currentSrc || images[idx].src;
      big.alt = images[idx].alt || '';
      box.classList.add('is-open');
      document.body.classList.add('aurora-no-scroll');
    }

    function hide() {
      box.classList.remove('is-open');
      document.body.classList.remove('aurora-no-scroll');
    }

    on(body, 'click', function (e) {
      var img = e.target.closest && e.target.closest('img');
      if (!img) { return; }
      var at = images.indexOf(img);
      if (at === -1) { return; }
      e.preventDefault();
      show(at);
    });
    on(box.querySelector('.aurora-lightbox__close'), 'click', hide);
    on(box.querySelector('.aurora-lightbox__prev'), 'click', function () { show(idx - 1); });
    on(box.querySelector('.aurora-lightbox__next'), 'click', function () { show(idx + 1); });
    on(box, 'click', function (e) { if (e.target === box) { hide(); } });
    on(document, 'keydown', function (e) {
      if (!box.classList.contains('is-open')) { return; }
      if (e.key === 'Escape') { hide(); }
      else if (e.key === 'ArrowLeft') { show(idx - 1); }
      else if (e.key === 'ArrowRight') { show(idx + 1); }
    });
    images.forEach(function (img) { img.classList.add('aurora-zoomable'); });
  })();

  (function tocState() {
    var bound = false, byId = {}, targets = [], links = [], currentId = '';

    function setup() {
      if (bound) { return true; }
      links = $$('.aurora-toc a[href^="#"]');
      if (!links.length) { return false; }
      byId = {};
      targets = [];
      links.forEach(function (a) {
        var id = a.getAttribute('href').slice(1);
        var el = id ? document.getElementById(id) : null;
        if (el) { byId[id] = a; targets.push(el); }
      });
      if (!targets.length) { return false; }
      bound = true;
      update();
      return true;
    }

    // Highlight the last heading that has scrolled past the reading line.
    // An IntersectionObserver band was tried first and proved too jumpy: a
    // heading that sits just above the band reported nothing at all.
    function update() {
      if (!bound) { return; }
      var line = window.scrollY + 140;
      var best = null;
      for (var i = 0; i < targets.length; i++) {
        var top = targets[i].getBoundingClientRect().top + window.scrollY;
        if (top <= line) { best = targets[i]; } else { break; }
      }
      var id = best ? best.id : targets[0].id;
      if (id === currentId) { return; }
      currentId = id;
      links.forEach(function (a) { a.classList.remove('is-active'); });
      if (byId[id]) { byId[id].classList.add('is-active'); }
    }

    var tries = 0;
    (function retry() {
      if (setup() || tries++ > 24) { return; }
      setTimeout(retry, 150);
    })();

    on(window, 'scroll', throttle(function () { if (!setup()) { return; } update(); }, 120), { passive: true });
    on(window, 'resize', throttle(update, 200), { passive: true });
  })();

  on(document, 'click', function (e) {
    var btn = e.target.closest && e.target.closest('.aurora-follow');
    if (!btn || btn.disabled) { return; }
    e.preventDefault();
    var author = btn.getAttribute('data-author');
    if (!author) { return; }
    btn.disabled = true;
    request('aurora_follow', { author: author }).then(function (res) {
      btn.disabled = false;
      if (!res || !res.success) {
        toast((res && res.data && res.data.message) || i18n.error || 'Error');
        return;
      }
      var onLabel = btn.getAttribute('data-on');
      var offLabel = btn.getAttribute('data-off');
      btn.classList.toggle('is-active', !!res.data.following);
      if (res.data.following ? onLabel : offLabel) {
        btn.textContent = res.data.following ? onLabel : offLabel;
      }
      var card = btn.closest('.aurora-author');
      var counter = card ? card.querySelector('.aurora-author__followers') : null;
      if (counter) {
        counter.textContent = res.data.count + (counter.getAttribute('data-suffix') || '');
      }
      toast(res.data.message || '');
    }).catch(function () {
      btn.disabled = false;
      toast(i18n.error || 'Error');
    });
  });

  on(document, 'click', function (e) {
    var b = e.target.closest && e.target.closest('.aurora-share__wechat');
    if (!b) { return; }
    var tip = b.getAttribute('data-tip');
    if (tip) { setTimeout(function () { toast(tip); }, 80); }
  });

  /* ---------------------------------------------------------------------
     Comment emoji picker: insert at the caret, close on outside click
     --------------------------------------------------------------------- */
  on(document, 'click', function (e) {
    var toggle = e.target.closest && e.target.closest('.aurora-emoji__toggle');
    if (toggle) {
      var panel = toggle.parentNode.querySelector('.aurora-emoji__panel');
      if (!panel) { return; }
      var willOpen = panel.hasAttribute('hidden');
      if (willOpen) { panel.removeAttribute('hidden'); } else { panel.setAttribute('hidden', ''); }
      toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
      return;
    }

    var item = e.target.closest && e.target.closest('.aurora-emoji__item');
    if (item) {
      var box = document.getElementById('comment');
      if (!box) { return; }
      var ch = item.getAttribute('data-emoji') || '';
      var start = typeof box.selectionStart === 'number' ? box.selectionStart : box.value.length;
      var end = typeof box.selectionEnd === 'number' ? box.selectionEnd : start;
      box.value = box.value.slice(0, start) + ch + box.value.slice(end);
      box.focus();
      try { box.setSelectionRange(start + ch.length, start + ch.length); } catch (err) { /* ignore */ }
      return;
    }

    if (!(e.target.closest && e.target.closest('.aurora-emoji'))) {
      $$('.aurora-emoji__panel').forEach(function (p) { p.setAttribute('hidden', ''); });
      $$('.aurora-emoji__toggle').forEach(function (t) { t.setAttribute('aria-expanded', 'false'); });
    }
  });
})();


/* ---------------------------------------------------------------------
   Rewards: send a tip in points. Self-contained on purpose — the section
   carries its own ajax URL and nonce, so this keeps working even if the
   block above ever bails out early.
   --------------------------------------------------------------------- */
(function () {
  var section = document.querySelector('.aurora-tip');
  if (!section || !section.querySelector('.aurora-tip__btn[data-amount]')) return;

  var ajax = section.getAttribute('data-ajax');
  var nonce = section.getAttribute('data-nonce');
  var msg = section.querySelector('.aurora-tip__msg');
  var busy = false;

  function say(text, kind) {
    if (!msg) return;
    msg.textContent = text;
    msg.className = 'aurora-tip__msg is-' + kind;
  }

  section.addEventListener('click', function (e) {
    var btn = e.target.closest('.aurora-tip__btn[data-amount]');
    if (!btn || busy) return;
    e.preventDefault();
    busy = true;
    btn.disabled = true;

    var body = new FormData();
    body.append('action', 'aurora_tip');
    body.append('nonce', nonce);
    body.append('id', section.getAttribute('data-post'));
    body.append('amount', btn.getAttribute('data-amount'));

    fetch(ajax, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.success) {
          var c = section.querySelector('[data-tip-count]');
          var t = section.querySelector('[data-tip-total]');
          if (c) c.textContent = res.data.count;
          if (t) t.textContent = res.data.total;
          say(res.data.message, 'ok');
        } else {
          say((res && res.data && res.data.message) || '打赏失败', 'error');
        }
      })
      .catch(function () { say('网络异常，请重试。', 'error'); })
      .finally(function () { busy = false; btn.disabled = false; });
  });
})();


/* ---------------------------------------------------------------------
   Front-end editor. contenteditable + execCommand keeps this dependency
   free and produces exactly the HTML the server already sanitises.
   --------------------------------------------------------------------- */
(function () {
  var root = document.querySelector('.aurora-editor');
  if (!root) return;

  var ajax = root.getAttribute('data-ajax');
  var nonce = root.getAttribute('data-nonce');
  var postId = parseInt(root.getAttribute('data-post') || '0', 10);
  var canvas = root.querySelector('[data-canvas]');
  var titleEl = root.querySelector('[data-title]');
  var catEl = root.querySelector('[data-cat]');
  var tagsEl = root.querySelector('[data-tags]');
  var stateEl = root.querySelector('[data-state]');
  var savedEl = root.querySelector('[data-saved]');
  var countEl = root.querySelector('[data-count]');
  var msgEl = root.querySelector('[data-message]');
  var fileEl = root.querySelector('[data-file]');
  var dirty = false, saving = false;

  function plain() { return (canvas.innerText || '').trim(); }
  function recount() {
    var n = plain().replace(/\s/g, '').length;
    if (countEl) countEl.textContent = n + ' 字';
  }
  function mark(on) {
    dirty = on;
    if (on && savedEl) savedEl.textContent = '未保存的更改';
  }
  function say(t, kind) {
    if (!msgEl) return;
    msgEl.textContent = t;
    msgEl.className = 'aurora-editor__message is-' + (kind || 'ok');
  }

  canvas.addEventListener('input', function () { mark(true); recount(); });
  titleEl.addEventListener('input', function () { mark(true); });
  if (catEl) catEl.addEventListener('change', function () { mark(true); });
  if (tagsEl) tagsEl.addEventListener('input', function () { mark(true); });
  recount();

  function upload(file) {
    if (!file) return;
    var body = new FormData();
    body.append('action', 'aurora_editor_upload');
    body.append('nonce', nonce);
    body.append('file', file);
    if (savedEl) savedEl.textContent = '图片上传中…';

    fetch(ajax, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.success) {
          say((res && res.data && res.data.message) || '上传失败', 'error');
          if (savedEl) savedEl.textContent = '';
          return;
        }
        canvas.focus();
        var img = document.createElement('img');
        img.src = res.data.url;
        img.alt = res.data.title || '';
        var sel = window.getSelection();
        if (sel && sel.rangeCount) {
          var range = sel.getRangeAt(0);
          range.deleteContents();
          range.insertNode(img);
          range.collapse(false);
        } else {
          canvas.appendChild(img);
        }
        mark(true);
        recount();
        if (savedEl) savedEl.textContent = '图片已插入';
      })
      .catch(function () { say('上传失败', 'error'); });
  }

  Array.prototype.forEach.call(root.querySelectorAll('[data-cmd]'), function (btn) {
    // Keep the caret where it is when a toolbar button is pressed.
    btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
    btn.addEventListener('click', function () {
      var cmd = btn.getAttribute('data-cmd');
      canvas.focus();
      switch (cmd) {
        case 'h2': document.execCommand('formatBlock', false, '<h2>'); break;
        case 'quote': document.execCommand('formatBlock', false, '<blockquote>'); break;
        case 'code': document.execCommand('formatBlock', false, '<pre>'); break;
        case 'bold': document.execCommand('bold'); break;
        case 'italic': document.execCommand('italic'); break;
        case 'ul': document.execCommand('insertUnorderedList'); break;
        case 'ol': document.execCommand('insertOrderedList'); break;
        case 'undo': document.execCommand('undo'); break;
        case 'redo': document.execCommand('redo'); break;
        case 'link':
          var url = window.prompt('链接地址');
          if (url) document.execCommand('createLink', false, url);
          break;
        case 'image':
          if (fileEl) fileEl.click();
          return;
      }
      mark(true);
      recount();
    });
  });

  if (fileEl) {
    fileEl.addEventListener('change', function () {
      upload(fileEl.files[0]);
      fileEl.value = '';
    });
  }

  function payload() {
    var body = new FormData();
    body.append('nonce', nonce);
    body.append('id', postId);
    body.append('title', titleEl.value);
    body.append('content', canvas.innerHTML);
    if (catEl) body.append('cat', catEl.value);
    if (tagsEl) body.append('tags', tagsEl.value);
    return body;
  }

  function save(auto) {
    if (saving) return;
    if (!titleEl.value.trim() && !plain()) {
      if (!auto) say('还没有内容可以保存。', 'error');
      return;
    }
    saving = true;
    if (savedEl) savedEl.textContent = '保存中…';
    var body = payload();
    body.append('action', 'aurora_editor_save');

    fetch(ajax, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        saving = false;
        if (res && res.success) {
          postId = res.data.id;
          root.setAttribute('data-post', postId);
          mark(false);
          if (savedEl) savedEl.textContent = '已保存 ' + res.data.saved_at;
          if (!auto) say(res.data.message || '已保存', 'ok');
        } else {
          if (savedEl) savedEl.textContent = '保存失败';
          if (!auto) say((res && res.data && res.data.message) || '保存失败', 'error');
        }
      })
      .catch(function () {
        saving = false;
        if (savedEl) savedEl.textContent = '保存失败';
      });
  }

  function submit() {
    var body = payload();
    body.append('action', 'aurora_editor_submit');
    say('提交中…', 'ok');

    fetch(ajax, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.success) {
          postId = res.data.id;
          root.setAttribute('data-post', postId);
          mark(false);
          if (savedEl) savedEl.textContent = '已提交';
          say(res.data.message, 'ok');
          if (stateEl) stateEl.textContent = res.data.status === 'publish' ? '已发布' : '待审核';
        } else {
          say((res && res.data && res.data.message) || '提交失败', 'error');
        }
      })
      .catch(function () { say('提交失败', 'error'); });
  }

  var saveBtn = root.querySelector('[data-save]');
  var submitBtn = root.querySelector('[data-submit]');
  if (saveBtn) saveBtn.addEventListener('click', function () { save(false); });
  if (submitBtn) submitBtn.addEventListener('click', submit);

  document.addEventListener('keydown', function (e) {
    if (!(e.metaKey || e.ctrlKey)) return;
    if (e.key === 's') { e.preventDefault(); save(false); }
    if (e.key === 'Enter') { e.preventDefault(); submit(); }
  });

  setInterval(function () { if (dirty && !saving) save(true); }, 45000);

  window.addEventListener('beforeunload', function (e) {
    if (!dirty) return;
    e.preventDefault();
    e.returnValue = '';
  });
})();


/* ---------------------------------------------------------------------
   Post rating: one vote per visitor, aggregate updated in place.
   --------------------------------------------------------------------- */
(function () {
  var root = document.querySelector('.aurora-rating');
  if (!root) return;

  var ajax = root.getAttribute('data-ajax');
  var nonce = root.getAttribute('data-nonce');
  var msg = root.querySelector('[data-msg]');
  var stars = Array.prototype.slice.call(root.querySelectorAll('.aurora-rating__star'));
  var busy = false;
  var voted = stars.some(function (s) { return s.classList.contains('is-on'); });

  function paint(upTo) {
    stars.forEach(function (s, i) {
      s.classList.toggle('is-on', i < upTo);
      s.setAttribute('aria-checked', String(i === upTo - 1));
    });
  }

  root.addEventListener('mouseover', function (e) {
    var s = e.target.closest('.aurora-rating__star');
    if (!s || voted) return;
    paint(stars.indexOf(s) + 1);
  });

  root.addEventListener('mouseleave', function () {
    if (!voted) paint(0);
  });

  root.addEventListener('click', function (e) {
    var star = e.target.closest('.aurora-rating__star');
    if (!star || busy || voted) return;

    busy = true;
    var score = parseInt(star.getAttribute('data-value'), 10);
    var body = new FormData();
    body.append('action', 'aurora_rate');
    body.append('nonce', nonce);
    body.append('id', root.getAttribute('data-post'));
    body.append('score', score);

    fetch(ajax, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        busy = false;
        if (res && res.success) {
          voted = true;
          paint(score);
          var avg = root.querySelector('[data-avg]');
          var cnt = root.querySelector('[data-count]');
          if (avg) avg.textContent = (Math.round(res.data.avg * 10) / 10).toFixed(1);
          if (cnt) cnt.textContent = res.data.count;
          // 第一票之后才有东西可报，把藏起来的「x.x / 5 · n 人评分」放出来。
          var box = root.querySelector('.aurora-rating__summary');
          if (box && parseInt(res.data.count, 10) > 0) box.hidden = false;
          if (msg) msg.textContent = res.data.message;
        } else {
          if (msg) msg.textContent = (res && res.data && res.data.message) || '评分失败';
          paint(0);
        }
      })
      .catch(function () {
        busy = false;
        if (msg) msg.textContent = '网络异常，请重试。';
      });
  });
})();


/* ---------------------------------------------------------------------
   Message centre: mark one / all as read without a reload.
   --------------------------------------------------------------------- */
(function () {
  var list = document.getElementById('aurora-inbox');
  var btn = document.getElementById('aurora-inbox-readall');
  if (!list && !btn) return;

  var ajax = (btn || document.querySelector('.aurora-inbox-readall') || {}).getAttribute
    ? (btn || document.querySelector('.aurora-inbox-readall')).getAttribute('data-ajax')
    : '';
  var nonce = (btn || document.querySelector('.aurora-inbox-readall') || {}).getAttribute
    ? (btn || document.querySelector('.aurora-inbox-readall')).getAttribute('data-nonce')
    : '';

  function send(id) {
    var body = new FormData();
    body.append('action', 'aurora_inbox_read');
    body.append('nonce', nonce);
    body.append('id', id);
    return fetch(ajax, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }

  function setUnread(n) {
    Array.prototype.forEach.call(document.querySelectorAll('[data-inbox-unread]'), function (el) {
      el.textContent = n;
    });
    var bell = document.getElementById('aurora-bell-dot');
    if (bell) bell.hidden = !n;
  }

  if (list) {
    list.addEventListener('click', function (e) {
      var mark = e.target.closest('.aurora-inbox__mark');
      if (!mark) return;
      e.preventDefault();
      var item = mark.closest('.aurora-inbox__item');
      send(mark.getAttribute('data-id')).then(function (res) {
        if (!res || !res.success) return;
        item.classList.remove('is-unread');
        item.classList.add('is-read');
        mark.remove();
        setUnread(res.data.unread);
      });
    });
  }

  if (btn) {
    btn.addEventListener('click', function () {
      send(0).then(function (res) {
        if (!res || !res.success) return;
        Array.prototype.forEach.call(document.querySelectorAll('.aurora-inbox__item.is-unread'), function (item) {
          item.classList.remove('is-unread');
          item.classList.add('is-read');
        });
        Array.prototype.forEach.call(document.querySelectorAll('.aurora-inbox__mark'), function (m) { m.remove(); });
        setUnread(0);
        btn.remove();
      });
    });
  }
})();


/* ---------------------------------------------------------------------
   Home slideshow: one track, one index. Autoplay pauses on hover, focus and
   when the tab is hidden; reduced motion turns it off entirely.
   --------------------------------------------------------------------- */
(function () {
  var root = document.querySelector('.aurora-slider');
  if (!root) return;

  var track = root.querySelector('.aurora-slider__track');
  var slides = Array.prototype.slice.call(root.querySelectorAll('.aurora-slider__slide'));
  var dots = Array.prototype.slice.call(root.querySelectorAll('.aurora-slider__dot'));
  if (slides.length < 2 || !track) return;

  var index = 0;
  var timer = null;
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var interval = parseInt(root.getAttribute('data-interval'), 10) || 5000;
  var autoplay = root.getAttribute('data-autoplay') === '1' && !reduced;

  function go(next, userAction) {
    index = (next + slides.length) % slides.length;
    track.style.transform = 'translate3d(' + (-index * 100) + '%,0,0)';

    slides.forEach(function (s, i) {
      s.classList.toggle('is-active', i === index);
      s.setAttribute('aria-hidden', i === index ? 'false' : 'true');
      var link = s.querySelector('a');
      if (link) {
        if (i === index) link.removeAttribute('tabindex');
        else link.setAttribute('tabindex', '-1');
      }
    });

    dots.forEach(function (d, i) {
      d.classList.toggle('is-active', i === index);
      d.setAttribute('aria-selected', i === index ? 'true' : 'false');
    });

    if (userAction) restart();
  }

  function restart() {
    if (!autoplay) return;
    clearInterval(timer);
    if (document.hidden) return;
    timer = setInterval(function () { go(index + 1); }, interval);
  }

  dots.forEach(function (d, i) {
    d.addEventListener('click', function () { go(i, true); });
  });

  var prev = root.querySelector('.aurora-slider__nav--prev');
  var next = root.querySelector('.aurora-slider__nav--next');
  if (prev) prev.addEventListener('click', function () { go(index - 1, true); });
  if (next) next.addEventListener('click', function () { go(index + 1, true); });

  root.addEventListener('mouseenter', function () { clearInterval(timer); });
  root.addEventListener('mouseleave', restart);
  root.addEventListener('focusin', function () { clearInterval(timer); });
  root.addEventListener('focusout', restart);

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) clearInterval(timer);
    else restart();
  });

  root.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowLeft') { e.preventDefault(); go(index - 1, true); }
    if (e.key === 'ArrowRight') { e.preventDefault(); go(index + 1, true); }
  });

  // Pointer swipe, with a threshold so a slightly shaky tap is not a slide.
  var startX = null;
  root.addEventListener('pointerdown', function (e) { if (e.pointerType !== 'mouse') startX = e.clientX; });
  root.addEventListener('pointerup', function (e) {
    if (startX === null) return;
    var delta = e.clientX - startX;
    startX = null;
    if (Math.abs(delta) > 40) go(index + (delta < 0 ? 1 : -1), true);
  });

  restart();
})();
