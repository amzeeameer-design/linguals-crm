(() => {
'use strict';
const form=document.querySelector('[data-nda-editor]');if(!form)return;
const tokens={contractor_name:'Preview Applicant',contractor_email:'applicant@example.test',reference:'PREVIEW',effective_date:new Date().toLocaleDateString('en-GB')};
let dirty=false;
function preview(){for(const field of ['title','body','footer']){document.querySelector('[data-nda-preview-'+field+']').textContent=form.querySelector('[name="'+field+'"]').value.replace(/\{\{(contractor_name|contractor_email|reference|effective_date)\}\}/g,(_,key)=>tokens[key]);}}
form.addEventListener('input',()=>{dirty=true;preview();});
form.addEventListener('click',event=>{const button=event.target.closest('[data-nda-token]');if(!button)return;const body=form.querySelector('[name=body]');body.setRangeText('{{'+button.dataset.ndaToken+'}}',body.selectionStart,body.selectionEnd,'end');body.focus();dirty=true;preview();});
form.addEventListener('submit',()=>dirty=false);window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});preview();
})();
