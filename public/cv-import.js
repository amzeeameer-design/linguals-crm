/* Local CV import. No file content leaves this browser. */
(() => {
'use strict';
const maxBytes=10*1024*1024;
function extract(text,{languages=[],countries=[],services=[],domains=[]}={}){
  text=String(text).replace(/\u0000/g,'').slice(0,100000);
  const lines=text.split(/\r?\n/).map(x=>x.trim()).filter(Boolean);
  const escaped=s=>s.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
  const mentions=value=>new RegExp('(?:^|[^\\p{L}])'+escaped(value)+'(?:$|[^\\p{L}])','iu').test(text);
  const labeled=label=>lines.find(l=>new RegExp('^'+label+'\\s*[:：]','i').test(l))?.replace(/^[^:：]+[:：]\s*/,'')||'';
  let name=labeled('(?:full name|name)');
  if(!name){const first=lines.find(l=>! /^(?:curriculum vitae|resume|résumé|cv)$/i.test(l));if(first&&first.length<85&&/^[\p{L} .’'\-]{3,}$/u.test(first)&&first.split(/\s+/).length>=2&&first.split(/\s+/).length<=6&&! /professional|translator|engineer|summary|experience|contact|education/i.test(first))name=first;}
  const detectedCountries=countries.filter(mentions);
  const countryLabel=labeled('(?:country|country of residence|location)');
  const country=countries.find(c=>c.toLowerCase()===countryLabel.toLowerCase())||countries.find(c=>countryLabel.toLowerCase().endsWith(", "+c.toLowerCase()))||(detectedCountries.length===1?detectedCountries[0]:'');
  const languageHeading=lines.findIndex(l=>/^(?:working\s+)?languages?(?:\s+(?:skills|proficiency))?\s*[:：]?/i.test(l));
  const languageText=languageHeading>=0?lines.slice(languageHeading,languageHeading+10).join('\n'):'';
  const selected=languages.filter(l=>new RegExp('(?:^|[^\\p{L}])'+escaped(l)+'(?:$|[^\\p{L}])','iu').test(languageText));
  const languageValues=selected.map(language=>{const line=languageText.split('\n').find(x=>new RegExp('(?:^|[^\\p{L}])'+escaped(language)+'(?:$|[^\\p{L}])','iu').test(x))||'';const proficiency=line.match(new RegExp(escaped(language)+'\\s*[:\\-–(]*\\s*(?:CEFR\\s*)?(A1|A2|B1|B2|C1|C2|Native)\\b','i'))?.[1]||'';return {language,proficiency:proficiency.toLowerCase()==='native'?'Native':proficiency.toUpperCase()};});
  const aboutHeading=lines.findIndex(l=>/^(?:professional summary|summary|profile|about me)\s*[:：]?/i.test(l));
  let about='';if(aboutHeading>=0){const section=[];for(let i=aboutHeading;i<Math.min(lines.length,aboutHeading+7);i++){if(i>aboutHeading&&/^(?:work experience|experience|education|languages|skills|certifications)\s*[:：]?$/i.test(lines[i]))break;section.push(i===aboutHeading?lines[i].replace(/^(?:professional summary|summary|profile|about me)\s*[:：]?\s*/i,''):lines[i]);}about=section.filter(Boolean).join(' ').slice(0,1200);}
  return {name:name.slice(0,120),email:text.match(/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i)?.[0]||'',country,timezone:'',about,services:services.filter(v=>mentions(v)||({Translation:"translator",Interpretation:"interpreter",Proofreading:"proofreader",Transcription:"transcriber"}[v]&&mentions({Translation:"translator",Interpretation:"interpreter",Proofreading:"proofreader",Transcription:"transcriber"}[v]))),domains:domains.filter(v=>mentions(v)||({"E-commerce":"ecommerce","IT":"information technology","Movies & dramas":"film","Tourism":"travel"}[v]&&mentions({"E-commerce":"ecommerce","IT":"information technology","Movies & dramas":"film","Tourism":"travel"}[v]))),languages:languageValues};
}
async function docxText(buffer){
  const view=new DataView(buffer),bytes=new Uint8Array(buffer);let end=-1;
  for(let i=bytes.length-22;i>=Math.max(0,bytes.length-65557);i--)if(view.getUint32(i,true)===0x06054b50){end=i;break;}
  if(end<0)throw Error('This DOCX file could not be read. Try a PDF or paste the text.');
  let pos=view.getUint32(end+16,true);const count=view.getUint16(end+10,true);if(count>3000)throw Error('This document is too complex. Paste its text instead.');
  for(let n=0;n<count;n++){
    if(pos+46>bytes.length||view.getUint32(pos,true)!==0x02014b50)break;
    const method=view.getUint16(pos+10,true),flags=view.getUint16(pos+8,true),size=view.getUint32(pos+20,true),plainSize=view.getUint32(pos+24,true),nameSize=view.getUint16(pos+28,true),extra=view.getUint16(pos+30,true),comment=view.getUint16(pos+32,true),offset=view.getUint32(pos+42,true);
    const name=new TextDecoder().decode(bytes.slice(pos+46,pos+46+nameSize));pos+=46+nameSize+extra+comment;
    if(name!=='word/document.xml')continue;
    if(flags&1)throw Error('Password-protected documents are not supported.');
    if(plainSize>8*1024*1024||offset+30>bytes.length)throw Error('This document is too large to read safely.');
    const start=offset+30+view.getUint16(offset+26,true)+view.getUint16(offset+28,true);if(start+size>bytes.length)throw Error('This DOCX file appears incomplete.');
    let xmlBytes=bytes.slice(start,start+size);
    if(method===8){const reader=new Blob([xmlBytes]).stream().pipeThrough(new DecompressionStream('deflate-raw')).getReader();const chunks=[];let total=0;for(;;){const {done,value}=await reader.read();if(done)break;total+=value.length;if(total>8*1024*1024){await reader.cancel();throw Error('This document is too large to read safely.');}chunks.push(value);}xmlBytes=new Uint8Array(total);let i=0;for(const chunk of chunks){xmlBytes.set(chunk,i);i+=chunk.length;}}
    else if(method!==0)throw Error('This DOCX compression format is not supported.');
    const xml=new DOMParser().parseFromString(new TextDecoder().decode(xmlBytes),'application/xml');if(xml.getElementsByTagName('parsererror').length)throw Error('This DOCX file could not be read.');
    return Array.from(xml.getElementsByTagNameNS('*','p')).map(p=>Array.from(p.getElementsByTagNameNS('*','t')).map(t=>t.textContent).join('')).join('\n');
  }
  throw Error('No readable text was found in this DOCX.');
}
async function read(file){
 if(!file||!file.size)throw Error('Choose a non-empty CV or résumé.');if(file.size>maxBytes)throw Error('Choose a file smaller than 10 MB.');
 const ext=file.name.split('.').pop().toLowerCase();if(!['pdf','docx','txt'].includes(ext))throw Error('Choose PDF, DOCX or TXT. For older DOC files, paste the text below.');
 if(ext==='txt')return (await file.text()).slice(0,100000);
 const buffer=await file.arrayBuffer();if(ext==='docx')return docxText(buffer);
 const pdfjs=await import(window.LingualsPaths.asset('vendor/pdfjs/pdf.mjs'));pdfjs.GlobalWorkerOptions.workerSrc=window.LingualsPaths.asset('vendor/pdfjs/pdf.worker.mjs');
 const task=pdfjs.getDocument({data:new Uint8Array(buffer),isEvalSupported:false,cMapUrl:window.LingualsPaths.asset('vendor/pdfjs/cmaps/'),cMapPacked:true,standardFontDataUrl:window.LingualsPaths.asset('vendor/pdfjs/standard_fonts/')});let doc;
 try{doc=await task.promise;if(doc.numPages>30)throw Error('Use a CV with 30 pages or fewer.');let text='';for(let i=1;i<=doc.numPages;i++){const page=await doc.getPage(i);const content=await page.getTextContent();text+=content.items.map(item=>item.str+(item.hasEOL?'\n':' ')).join('')+'\n';if(text.length>100000)break;}if(!text.trim())throw Error('This PDF appears scanned. Paste the CV text or use a text-based PDF.');return text.slice(0,100000);}finally{await task.destroy();}
}
window.LingualsCV=Object.freeze({extract,read});
})();
