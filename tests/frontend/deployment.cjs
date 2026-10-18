process.chdir(require('node:path').resolve(__dirname, '../..'));
const fs=require('fs'),vm=require('vm'),assert=require('node:assert/strict'),cp=require('child_process');
const root='public';let checks=0;
for(const prefix of ['/', '/demo/', '/clients/linguals/'])for(const page of ['workspace','apply','admin/applications/12']){
const origin='https://example.com';const context={URL,window:{},location:{href:origin+prefix+page},document:{currentScript:{src:origin+prefix+'deployment.js'}}};
vm.runInNewContext(fs.readFileSync(root+'/deployment.js','utf8'),context);
for(const path of ['login','logout','apply','workspace','portal','admin/applications/12/review']){assert.equal(context.window.LingualsPaths.app(path),origin+prefix+path);checks++;}
assert.equal(context.window.LingualsPaths.asset('branding/linguals-logo.png'),origin+prefix+'branding/linguals-logo.png');checks++;
}
const c={URL,window:{LINGUALS_BASE_URL:'https://example.com/demo'},location:{href:'https://example.com/demo/workspace'},document:{currentScript:{src:'https://cdn.example.com/assets/deployment.js'}}};vm.runInNewContext(fs.readFileSync(root+'/deployment.js','utf8'),c);assert.equal(c.window.LingualsPaths.app('logout'),'https://example.com/demo/logout');assert.equal(c.window.LingualsPaths.asset('branding/linguals-logo.png'),'https://cdn.example.com/assets/branding/linguals-logo.png');checks+=2;
for(const name of fs.readdirSync(root).filter(f=>f.endsWith('.js'))){cp.execFileSync(process.execPath,['--check',root+'/'+name]);checks++;}
for(const name of ['workspace.html','apply.html']){const html=fs.readFileSync(root+'/'+name,'utf8');assert.ok(html.indexOf('deployment.js')<html.indexOf('defer'));checks++;}
console.log(checks+' deployment URL and syntax checks passed.');
