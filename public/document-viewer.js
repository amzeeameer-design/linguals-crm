for (const viewer of document.querySelectorAll('[data-pdf-viewer]')) {
 const status=viewer.querySelector('[data-pdf-status]'),canvas=viewer.querySelector('canvas'),pageInput=viewer.querySelector('[data-pdf-page]'),wrap=viewer.querySelector('.pdf-canvas-wrap');
 let pdf,pageNumber=1,zoom=1,rendering=false,rerender=false;
 const buttons=[...viewer.querySelectorAll('button')];
 async function render(){
  if(!pdf)return;if(rendering){rerender=true;return;}rendering=true;buttons.forEach(button=>button.disabled=true);
  try {
   const page=await pdf.getPage(pageNumber),base=page.getViewport({scale:1}),fit=Math.max(160,wrap.clientWidth-24)/base.width,viewport=page.getViewport({scale:Math.min(3,fit*zoom)}),density=Math.min(2,window.devicePixelRatio||1);
   canvas.width=Math.ceil(viewport.width*density);canvas.height=Math.ceil(viewport.height*density);canvas.style.width=viewport.width+'px';canvas.style.height=viewport.height+'px';
   await page.render({canvasContext:canvas.getContext('2d'),viewport,transform:density===1?null:[density,0,0,density,0,0]}).promise;
   const text=await page.getTextContent();viewer.querySelector('[data-pdf-text]').textContent=text.items.map(item=>item.str||'').join(' ');
   canvas.setAttribute('aria-label','Document page '+pageNumber+' of '+pdf.numPages);pageInput.value=pageNumber;viewer.querySelector('[data-pdf-total]').textContent='/ '+pdf.numPages;status.textContent='Page '+pageNumber+' of '+pdf.numPages;
  }catch(error){status.textContent='Preview could not be rendered. Open PDF or download the original to review it.';}
  finally{rendering=false;buttons.forEach(button=>button.disabled=false);viewer.querySelector('[data-pdf-prev]').disabled=pageNumber<=1;viewer.querySelector('[data-pdf-next]').disabled=pageNumber>=pdf.numPages;if(rerender){rerender=false;render();}}
 }
 viewer.querySelector('[data-pdf-prev]').addEventListener('click',()=>{pageNumber=Math.max(1,pageNumber-1);render();});viewer.querySelector('[data-pdf-next]').addEventListener('click',()=>{pageNumber=Math.min(pdf.numPages,pageNumber+1);render();});
 pageInput.addEventListener('change',()=>{pageNumber=Math.min(pdf?.numPages||1,Math.max(1,Number(pageInput.value)||1));render();});viewer.querySelector('[data-pdf-in]').addEventListener('click',()=>{zoom=Math.min(2,zoom+.2);render();});viewer.querySelector('[data-pdf-out]').addEventListener('click',()=>{zoom=Math.max(.5,zoom-.2);render();});viewer.querySelector('[data-pdf-fit]').addEventListener('click',()=>{zoom=1;render();});
 let resizeTimer;new ResizeObserver(()=>{clearTimeout(resizeTimer);resizeTimer=setTimeout(()=>render(),150);}).observe(wrap);
 try {
  const engine=await import(viewer.dataset.engine);engine.GlobalWorkerOptions.workerSrc=viewer.dataset.worker;
  const response=await fetch(viewer.dataset.url,{credentials:'same-origin',headers:{Accept:'application/pdf'}});if(!response.ok||!response.headers.get('Content-Type')?.includes('application/pdf'))throw new Error('Private preview unavailable');
  const task=engine.getDocument({data:new Uint8Array(await response.arrayBuffer()),cMapUrl:viewer.dataset.cmaps,cMapPacked:true,standardFontDataUrl:viewer.dataset.fonts,isEvalSupported:false});
  task.onPassword=()=>{status.textContent='This PDF is password protected. Ask for an unlocked PDF for review.';task.destroy();};pdf=await task.promise;pageInput.max=pdf.numPages;await render();
 }catch(error){if(!status.textContent.includes('password'))status.textContent='Preview unavailable. Ask for a readable PDF or use Download original. No assessment result is inferred from this error.';}
}
