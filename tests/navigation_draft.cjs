const fs=require('fs'),vm=require('vm'),assert=require('assert');
const code=fs.readFileSync(require('path').join(__dirname,'../public/app.js'),'utf8');
const begin=code.indexOf(" const recovered = document.querySelector('#m3l-navigation-draft');");
const end=code.indexOf(' const phone',begin);assert(begin>0&&end>begin);
function restore(fields,existing={}) {
 const inputs=Object.fromEntries(['name','email','phone','website'].map(k=>[k,{value:existing[k]||''}]));
 inputs.ddi={value:'55',options:[{value:'55'},{value:'1'}]};
 const error={textContent:''};
 const ctx={form:{elements:{namedItem:k=>inputs[k]}},document:{querySelector:s=>s==='#m3l-navigation-draft'?{textContent:JSON.stringify({fields})}:error}};
 vm.runInNewContext(code.slice(begin,end),ctx);
 return {inputs,error};
}
let r=restore({name:'Ana',email:'ana@example.com',phone:'11999999999',ddi:'1'});
assert.equal(r.inputs.name.value,'Ana');assert.equal(r.inputs.ddi.value,'1');assert(r.error.textContent.includes('ainda não foi confirmado'));
r=restore({email:'draft@example.com',ddi:'999'},{email:'autofill@example.com'});
assert.equal(r.inputs.email.value,'autofill@example.com');assert.equal(r.inputs.ddi.value,'55');
r=restore({name:{unexpected:true}});assert.equal(r.inputs.name.value,'');
console.log('OK: restaura campos e DDI válido, mantém autofill, ignora tipos inválidos, exige confirmação sem chamar API.');
