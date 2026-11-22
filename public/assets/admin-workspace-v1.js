(() => {
  'use strict';
  const sidebar = document.getElementById('admin-navigation');
  const toggle = document.querySelector('[data-cms-nav-toggle]');
  const closeButton = document.querySelector('[data-cms-nav-close]');
  const scrim = document.querySelector('[data-cms-nav-scrim]');
  const search = document.querySelector('[data-cms-menu-search]');
  if (!sidebar || !toggle || !scrim) return;

  const mobile = () => window.matchMedia('(max-width:960px)').matches;
  const normalized = text => String(text || '')
    .toLocaleLowerCase().normalize('NFKC')
    .replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').trim();
  let lastFocused = null;

  const setOpen = open => {
    const shouldOpen = Boolean(open && mobile());
    sidebar.classList.toggle('is-open', shouldOpen);
    toggle.setAttribute('aria-expanded', String(shouldOpen));
    scrim.hidden = !shouldOpen;
    document.body.classList.toggle('cms-nav-modal-open', shouldOpen);
    if (shouldOpen) {
      lastFocused = document.activeElement;
      (search || closeButton || sidebar).focus();
    } else if (lastFocused && document.contains(lastFocused)) {
      lastFocused.focus();
      lastFocused = null;
    }
  };

  toggle.addEventListener('click', () => setOpen(!sidebar.classList.contains('is-open')));
  closeButton?.addEventListener('click', () => setOpen(false));
  scrim.addEventListener('click', () => setOpen(false));
  sidebar.addEventListener('click', event => {
    if (mobile() && event.target.closest('a[href]')) setOpen(false);
  });
  window.addEventListener('resize', () => {
    if (!mobile() && sidebar.classList.contains('is-open')) setOpen(false);
  });

  const groups = Array.from(sidebar.querySelectorAll('.cms-nav-group'));
  const empty = sidebar.querySelector('[data-cms-menu-empty]');

  search?.addEventListener('input', () => {
    const q = normalized(search.value);
    let shown = 0;
    groups.forEach(group => {
      let visibleInGroup = 0;
      group.querySelectorAll('a.cms-nav-link').forEach(link => {
        const matched = normalized(link.textContent).includes(q);
        link.hidden = !matched;
        visibleInGroup += matched ? 1 : 0;
      });
      group.hidden = visibleInGroup === 0;
      shown += visibleInGroup;
    });
    if (empty) empty.hidden = shown !== 0;
  });

  document.addEventListener('keydown', event => {
    const target = event.target;
    const editing = target instanceof HTMLElement &&
      (target.isContentEditable || Boolean(target.closest('input,textarea,select,[contenteditable="true"]')));
    if (event.key === '/' && !editing && !event.ctrlKey && !event.altKey && !event.metaKey) {
      event.preventDefault();
      if (mobile() && !sidebar.classList.contains('is-open')) setOpen(true);
      search?.focus();
    }
    if (event.key === 'Escape') {
      if (sidebar.classList.contains('is-open')) {
        event.preventDefault();
        setOpen(false);
      } else if (document.activeElement === search && search.value) {
        search.value = '';
        search.dispatchEvent(new Event('input'));
      }
    }
    if (event.key === 'Tab' && sidebar.classList.contains('is-open')) {
      const focusable = Array.from(sidebar.querySelectorAll('button:not([disabled]),a[href]:not([hidden]),input:not([disabled])'))
        .filter(el => el.getClientRects().length > 0);
      if (!focusable.length) return;
      const first = focusable[0], last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault(); last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault(); first.focus();
      }
    }
  });
})();
