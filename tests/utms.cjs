process.chdir(require('path').join(__dirname,'..'));
const fs=require('fs'),vm=require('vm'),assert=require('assert');
const code=fs.readFileSync('public/app.js','utf8').split('const init =')[0];
const saved={};function capture(search,blocked=false){const ctx={URLSearchParams,location:{search},sessionStorage:{getItem:k=>saved[k],setItem:(k,v)=>{if(blocked)throw Error();saved[k]=v}}};vm.createContext(ctx);vm.runInContext(code,ctx);return JSON.parse(vm.runInContext('JSON.stringify(attribution)',ctx));}
assert.equal(capture('?utm_source=instagram&utm_campaign=A%20B').utm_campaign,'A B');assert.equal(capture('').utm_source,'instagram');assert.equal(capture('?utm_source=google').utm_campaign,'');assert.equal(capture('?utm_source=meta',true).utm_source,'meta');
const gs=fs.readFileSync('integracao/Pesquisa.gs','utf8');let rows=[],max=26;
const sheet={getLastRow:()=>rows.length,getMaxColumns:()=>max,insertColumnsAfter:(a,n)=>max+=n,setFrozenRows:()=>{},getRange(r,c,n,w){const range={getValues:()=>Array.from({length:n},(_,i)=>Array.from({length:w},(_,j)=>rows[r-1+i]?.[c-1+j]??'')),setValues(values){values.forEach((row,i)=>{rows[r-1+i]??=[];row.forEach((v,j)=>rows[r-1+i][c-1+j]=v)});return range;},setFontWeight:()=>range,setNumberFormat:()=>range,createTextFinder(id){return {matchEntireCell(){return this},findNext(){const i=rows.findIndex((row,i)=>i>0&&row[14]===id);return i<0?null:{getRow:()=>i+1}}}}};return range;}};
const ctx={ContentService:{createTextOutput:s=>({setMimeType:()=>JSON.parse(s)}),MimeType:{JSON:1}},PropertiesService:{getScriptProperties:()=>({getProperty:()=> 'token'})},LockService:{getScriptLock:()=>({waitLock(){},hasLock:()=>true,releaseLock(){}})},SpreadsheetApp:{openById:()=>({getSheetByName:()=>sheet}),flush(){}},console};vm.createContext(ctx);vm.runInContext(gs,ctx);
const data={token:'token',submissionId:'a'.repeat(48),name:'Teste',email:'x@y.com',phone:'+5511',answers:Array(10).fill('Resposta'),utms:{utm_source:'instagram',utm_campaign:'=formula'}};
const post=d=>ctx.doPost({postData:{contents:JSON.stringify(d)}});
assert.equal(post(data).ok,true);assert.equal(rows[1][15],'instagram');assert.equal(rows[1][17],"'=formula");assert.equal(post(data).ok,true);assert.equal(rows.length,2);
// Simula cabeçalho e linhas existentes com 15 colunas.
rows=rows.map(row=>row.slice(0,15));assert.equal(post({...data,submissionId:'b'.repeat(48)}).ok,true);assert.equal(rows[0][15],'utm_source');assert.equal(rows[1][14],'a'.repeat(48));assert.equal(rows.length,3);
assert.equal(post({...data,utms:{utm_source:42}}).ok,false);rows[0][15]='Outra coluna';assert.equal(post(data).ok,false);
console.log('OK: captura, recarga, troca de campanha, storage bloqueado, migração de cabeçalhos, fórmula neutralizada, deduplicação e validação.');
