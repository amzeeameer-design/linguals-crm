const {spawn} = require('node:child_process');
const {resolve} = require('node:path');
const {randomBytes} = require('node:crypto');
process.chdir(resolve(__dirname, '../..'));
const args = process.argv.slice(2);
const backend = args.includes('--backend');
const full = args.includes('--full');
const started = Date.now();
function run(command, parameters) {
  return new Promise(resolveResult => {
    const child = spawn(command, parameters, {stdio: 'inherit', env: {...process.env, APP_ENV: 'testing', APP_KEY: 'base64:' + randomBytes(32).toString('base64')}});
    child.once('error', error => {console.error(error.message); resolveResult(false);});
    child.once('close', code => resolveResult(code === 0));
  });
}
(async () => {
  const tasks = [];
  if (!backend) {
    for (const file of ['talent-matching.cjs', 'workspace-navigation.cjs', 'deployment.cjs']) tasks.push(run(process.execPath, [resolve(__dirname, file)]));
  }
  if (backend || full) {
    tasks.push(run(process.env.PHP_BINARY || 'php', ['vendor/phpunit/phpunit/phpunit', ...args.filter(arg => !['--backend', '--full'].includes(arg))]));
  }
  const results = await Promise.all(tasks);
  console.log(`Checks ${results.every(Boolean) ? 'passed' : 'failed'} in ${((Date.now() - started) / 1000).toFixed(1)} seconds.`);
  process.exitCode = results.every(Boolean) ? 0 : 1;
})();
