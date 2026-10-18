process.chdir(require('node:path').resolve(__dirname, '../..'));
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const base = 'public/';
async function render(page, records) {
  const root = {innerHTML: ''};
  let failure;
  const storage = {getItem: () => null, setItem() {}};
  const location = {search: '', hash: '#/admin/' + page, hostname: 'demo.thelinguals.com', href: 'https://demo.thelinguals.com/workspace#/admin/' + page};
  const context = {
    window: {LINGUALS: {demo: false, role: 'admin', name: 'Administrator', applications: records}, LingualsPaths: {asset: p => '/demo/' + p, app: p => '/demo/' + p}, addEventListener() {}, scrollTo() {}, lingualsWorkspaceLoadFailed() {failure = true;}},
    document: {addEventListener() {}, getElementById: () => root, querySelector: () => null, querySelectorAll: () => []},
    location, URLSearchParams, localStorage: storage, sessionStorage: storage, structuredClone, setTimeout, clearTimeout, scrollY: 0, innerWidth: 1366, history: {replaceState() {}}, console: {error(...args) {failure = args;}}
  };
  vm.createContext(context);
  vm.runInContext(fs.readFileSync(base + 'talent-search.js', 'utf8'), context);
  context.createTalentExplorer = context.window.createTalentExplorer;
  await vm.runInContext(fs.readFileSync(base + 'workspace.js', 'utf8'), context);
  assert(!failure, `${page} failed to render: ${JSON.stringify(failure)}`);
  assert(root.innerHTML.includes('class="shell"'), `${page} shell missing`);
  assert(root.innerHTML.includes(`page-${page}`), `${page} content missing`);
  if (page === 'talent') {
    const panel = root.innerHTML.slice(root.innerHTML.indexOf('aria-label="Talent filters"'), root.innerHTML.indexOf('id="t-results"'));
    for (const field of ['data-t-quick="services"', 'data-t-value="source"', 'data-t-value="target"', 'data-t-quick="domains"']) assert(panel.includes(field), field);
    assert(!panel.includes('data-t-jump'), 'Redundant jump control consumes primary filter space');
  }
}
(async () => {
  const record = {id: 1, name: 'Example Applicant', email: 'applicant@example.test', country: 'Brazil', status: 'submitted', kyc_status: 'pending', services: [{service: 'Translation', experience_years: 2, domains: ['Legal'], language_pairs: [{source_language: 'English', target_language: 'French'}]}], profile: {}, events: []};
  const pages = ['talent', 'dashboard', 'applications', 'verification', 'invitations'];
  for (const records of [[], [record], Array.from({length: 1279}, (_, i) => ({...record, id: i + 1}))]) {
    for (const page of pages) await render(page, records);
  }
  console.log('15 workspace navigation/render checks passed without the unused application wizard.');
})().catch(error => {console.error(error); process.exitCode = 1;});
