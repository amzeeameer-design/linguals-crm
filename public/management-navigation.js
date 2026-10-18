(() => {
  'use strict';
  const toggle = document.querySelector('[data-management-nav-toggle]');
  const shade = document.querySelector('[data-management-nav-shade]');
  const sidebar = document.getElementById('management-sidebar');
  if (!toggle || !shade || !sidebar) return;
  const mobile = matchMedia('(max-width: 900px)');
  let previousOverflow = document.body.style.overflow;
  function setOpen(open, restoreFocus = false) {
    const expanded = open && mobile.matches;
    if (expanded && !sidebar.classList.contains('open')) previousOverflow = document.body.style.overflow;
    sidebar.classList.toggle('open', expanded);
    sidebar.inert = mobile.matches && !expanded;
    toggle.setAttribute('aria-expanded', String(expanded));
    toggle.setAttribute('aria-label', expanded ? 'Close navigation' : 'Open navigation');
    shade.hidden = !expanded;
    document.body.style.overflow = expanded ? 'hidden' : previousOverflow;
    if (expanded) sidebar.querySelector('a')?.focus();
    else if (restoreFocus) toggle.focus();
  }
  toggle.addEventListener('click', () => setOpen(toggle.getAttribute('aria-expanded') !== 'true'));
  shade.addEventListener('click', () => setOpen(false, true));
  sidebar.addEventListener('click', event => {if (event.target.closest('a')) setOpen(false);});
  document.addEventListener('keydown', event => {
    if (toggle.getAttribute('aria-expanded') !== 'true') return;
    if (event.key === 'Escape') {event.preventDefault(); setOpen(false, true);}
    if (event.key === 'Tab') {
      const targets = [toggle, ...sidebar.querySelectorAll('a[href],button:not([disabled])')].filter(target => target.getClientRects().length);
      const first = targets[0], last = targets.at(-1);
      if (event.shiftKey && document.activeElement === first) {event.preventDefault(); last?.focus();}
      else if (!event.shiftKey && document.activeElement === last) {event.preventDefault(); first?.focus();}
    }
  });
  mobile.addEventListener('change', () => setOpen(false));
  setOpen(false);
})();
