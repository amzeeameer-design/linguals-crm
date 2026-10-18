/* Resolve routes independently of the domain or installation folder. */
(() => {
'use strict';
const script = document.currentScript;
const assetRoot = new URL('.', script.src);
const appRoot = new URL(window.LINGUALS_BASE_URL || assetRoot.href, location.href);
if (!appRoot.pathname.endsWith('/')) appRoot.pathname += '/';
const resolve = (root, path) => new URL(String(path).replace(/^\/+/, ''), root).href;
window.LingualsPaths = Object.freeze({app: path => resolve(appRoot, path), asset: path => resolve(assetRoot, path)});
})();
