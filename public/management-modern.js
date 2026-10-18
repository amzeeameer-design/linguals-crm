document.querySelectorAll('[data-management-tabs]').forEach(root => {
 const tabs = [...root.querySelectorAll('[role="tab"]')];
 const panels = [...root.querySelectorAll('[data-tab-panel]')];
 function activate(tab, focus = false) {
  tabs.forEach(button => {const active = button === tab; button.setAttribute('aria-selected', String(active)); button.tabIndex = active ? 0 : -1;});
  panels.forEach(panel => {panel.hidden = panel.dataset.tabPanel !== tab.dataset.tab;});
  history.replaceState(null, '', location.pathname + location.search + '#' + tab.dataset.tab);
  if (focus) tab.focus();
 }
 tabs.forEach((tab, index) => {
  tab.addEventListener('click', () => activate(tab));
  tab.addEventListener('keydown', event => {
   let next;
   if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
   if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
   if (event.key === 'Home') next = 0;
   if (event.key === 'End') next = tabs.length - 1;
   if (next !== undefined) {event.preventDefault(); activate(tabs[next], true);}
  });
 });
 const initial = tabs.find(tab => '#' + tab.dataset.tab === location.hash) || tabs.find(tab => tab.dataset.tab === root.dataset.initialTab);
 if (initial) activate(initial);
 const composer = root.querySelector('[data-notification-compose]');
 if (composer) {
  const preview = document.createElement('div'); preview.className = 'modern-compose-preview';
  const label = document.createElement('small'); label.textContent = 'APPLICANT PREVIEW';
  const title = document.createElement('h3'); const body = document.createElement('p');
  preview.append(label, title, body); composer.parentElement.append(preview);
  const update = () => {title.textContent = composer.elements.title.value || 'Your notification subject'; body.textContent = composer.elements.body.value || 'Your update will appear here as the applicant sees it.';};
  composer.addEventListener('input', update); update();
 }
});
