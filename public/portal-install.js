(()=>{
if(!('serviceWorker' in navigator)||!window.isSecureContext)return;
const base=new URL('./',document.currentScript.src);
navigator.serviceWorker.register(new URL('portal-sw.js',base),{scope:base.pathname}).catch(()=>{});
let deferred;const mobile=matchMedia('(max-width:900px)').matches,standalone=matchMedia('(display-mode:standalone)').matches||navigator.standalone;
let dismissed=false;try{dismissed=!!sessionStorage.getItem('linguals-install-dismissed');}catch{}
if(!mobile||standalone||dismissed)return;
const ios=/iphone|ipad|ipod/i.test(navigator.userAgent);
function show(){document.getElementById('portal-install')?.remove();const panel=document.createElement('aside');panel.id='portal-install';panel.className='portal-install';panel.innerHTML='<div><b>The Linguals on your home screen</b><p>'+(ios?'In Safari, tap Share, then “Add to Home Screen”.':deferred?'Open your workspace like an app.':'Open your browser menu and choose “Add to Home Screen” or “Install app”.')+'</p></div>'+(deferred?'<button class="install-primary" type="button">Add shortcut</button>':'')+'<button class="install-dismiss" type="button" aria-label="Dismiss install suggestion">×</button>';document.body.append(panel);panel.querySelector('.install-dismiss').onclick=()=>{try{sessionStorage.setItem('linguals-install-dismissed','1');}catch{}panel.remove();};panel.querySelector('.install-primary')?.addEventListener('click',async()=>{await deferred.prompt();await deferred.userChoice;deferred=null;panel.remove();});}
addEventListener('beforeinstallprompt',event=>{event.preventDefault();deferred=event;show();});addEventListener('appinstalled',()=>document.getElementById('portal-install')?.remove());show();
})();
