const {execFileSync} = require('node:child_process');
const {resolve} = require('node:path');
process.chdir(resolve(__dirname, '../..'));
try {
  const files = execFileSync('git', ['-c', 'safe.directory=' + process.cwd().replaceAll('\\', '/'), 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], {encoding: 'utf8'}).split('\0').filter(Boolean);
  const unsafe = files.filter(file => {
    if (file === '.env.example' || file.endsWith('/.gitignore')) return false;
    return /(^|\/)\.env(?:\.|$)|(^|\/)auth\.json$|\.(?:sqlite\d*(?:-.*)?|db|sql(?:\.gz)?|pfx|p12|pem|bak|zip)$/i.test(file)
      || /^(?:vendor|node_modules|storage\/app\/(?:imports|private|public)|public\/(?:uploads|documents|cvs|profile-photos))\//.test(file);
  });
  if (unsafe.length) {
    console.error('Files requiring removal from the Git upload:\n' + unsafe.join('\n'));
    process.exitCode = 1;
  } else console.log(`Repository path safety passed (${files.length} publishable files). This is not a full secret-content scan.`);
} catch (error) {
  console.error('Repository safety check requires an initialized Git repository.');
  process.exitCode = 1;
}
