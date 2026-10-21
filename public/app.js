'use strict';
let csrf = '';
async function api(action, payload = {}) {
 const response = await fetch('api.php?action=' + encodeURIComponent(action), {method: action === 'init' ? 'GET' : 'POST', credentials: 'same-origin', headers: {'Content-Type':'application/json','X-CSRF-Token':csrf}, ...(action === 'init' ? {} : {body:JSON.stringify(payload)})});
 const data = await response.json().catch(() => ({message:'Resposta inválida do servidor.'}));
 if (!response.ok) throw new Error(data.message || 'Não foi possível concluir. Tente novamente.');
 return data;
}
const init = api('init').then(data => {csrf = data.csrf; return data;});
init.catch(() => {});

// Lead enviado somente após cadastro confirmado, na página de obrigada.
// A disponibilidade do Zaraz nunca bloqueia o formulário ou o redirecionamento.
const leadKey = 'm3l:lead:pending';
function markPendingLead() {
 try { sessionStorage.setItem(leadKey, '1'); }
 catch (_) { document.cookie = 'm3l_lead_pending=1; Path=/; SameSite=Lax; Secure'; }
}
function hasPendingLead() {
 try { if (sessionStorage.getItem(leadKey) === '1') return true; } catch (_) {}
 return document.cookie.split(';').some(cookie => cookie.trim() === 'm3l_lead_pending=1');
}
function clearPendingLead() {
 try { sessionStorage.removeItem(leadKey); } catch (_) {}
 document.cookie = 'm3l_lead_pending=; Path=/; Max-Age=0; SameSite=Lax; Secure';
}
function trackPendingLead() {
 if (!hasPendingLead()) return;
 let attempts = 0;
 function sendWhenReady() {
  if (!hasPendingLead()) return;
  if (typeof window.zaraz?.track !== 'function') {
   if (++attempts < 120) setTimeout(sendWhenReady, 250);
   return;
  }
  // Consome antes do envio para impedir repetição nesta página ou ao recarregar.
  clearPendingLead();
  try {
   Promise.resolve(window.zaraz.track('Lead')).catch(() => markPendingLead());
  } catch (_) { markPendingLead(); }
 }
 sendWhenReady();
}

const form = document.querySelector('#signup');
// Animações progressivas: o conteúdo continua visível se a API não existir.
if (form && typeof Element.prototype.animate === 'function') {
 const motionPreference = window.matchMedia('(prefers-reduced-motion: reduce)');
 const activeAnimations = new Set();
 let revealObserver;
 const reveal = (element, delay = 0) => {
  if (!element || motionPreference.matches) return;
  const animation = element.animate(
   [{opacity:0, transform:'translateY(14px)'}, {opacity:1, transform:'translateY(0)'}],
   {duration:650, delay, easing:'cubic-bezier(.22,1,.36,1)', fill:'backwards'}
  );
  activeAnimations.add(animation);
  animation.finished.then(() => activeAnimations.delete(animation)).catch(() => activeAnimations.delete(animation));
 };
 if (!motionPreference.matches) {
  // Conteúdo da primeira dobra visível imediatamente; animações só na biografia.
  if ('IntersectionObserver' in window) {
   revealObserver = new IntersectionObserver(entries => {
    entries.forEach(entry => {
     if (!entry.isIntersecting) return;
     reveal(entry.target);
     revealObserver.unobserve(entry.target);
    });
   }, {threshold:0.08, rootMargin:'0px 0px -20px 0px'});
   document.querySelectorAll('.about .portrait, .about .bio > *').forEach(element => revealObserver.observe(element));
  }
 }
 motionPreference.addEventListener('change', event => {
  if (event.matches) {
   revealObserver?.disconnect();
   activeAnimations.forEach(animation => animation.cancel());
   activeAnimations.clear();
  }
 });
}
if (form) {
 const phone = document.querySelector('#phone');
 const select = document.querySelector('#ddi');
 const countryBox = select.closest('.country');
 const trigger = document.createElement('button');
 trigger.type='button';trigger.className='ddi-trigger';trigger.setAttribute('aria-haspopup','dialog');
 const panel=document.createElement('dialog');panel.className='ddi-dialog';panel.id='ddi-dialog';panel.setAttribute('aria-labelledby','ddi-title');
 panel.innerHTML='<div class="ddi-heading"><h2 id="ddi-title">Escolha o país</h2><button type="button" class="ddi-close" aria-label="Fechar">×</button></div><label class="sr-only" for="ddi-search">Buscar país ou DDI</label><input id="ddi-search" type="search" placeholder="Buscar país ou DDI" autocomplete="off"><div class="ddi-options" aria-label="Países disponíveis"></div><p class="ddi-empty" hidden>Nenhum país encontrado.</p>';
 document.body.append(panel);countryBox.append(trigger);select.classList.add('ddi-native');
 document.querySelector('#flag').classList.add('ddi-native-flag');
 const search=panel.querySelector('input');const list=panel.querySelector('.ddi-options');
 const normalize=value=>value.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
 const choices=Array.from(select.options).map(option=>({option,name:option.textContent.split(' — ')[1],iso:option.dataset.country,code:option.value}));
 function updateTrigger(){const option=select.selectedOptions[0];trigger.replaceChildren();const flag=document.createElement('img');flag.src='assets/'+option.dataset.country.toLowerCase()+'.svg';flag.alt='';const code=document.createElement('span');code.textContent='+'+option.value;trigger.append(flag,code);trigger.setAttribute('aria-label',option.textContent+' — alterar país');}
 function renderCountries(){list.replaceChildren();const term=normalize(search.value.trim());const matches=choices.filter(c=>normalize(c.name+' '+c.iso+' +'+c.code).includes(term));matches.forEach(c=>{const button=document.createElement('button');button.type='button';button.className='ddi-option';const flag=document.createElement('img');flag.src='assets/'+c.iso.toLowerCase()+'.svg';flag.alt='';flag.loading='lazy';const name=document.createElement('span');name.textContent=c.name;const code=document.createElement('span');code.className='ddi-code';code.textContent='+'+c.code;button.append(flag,name,code);if(c.option.selected){button.classList.add('selected');button.setAttribute('aria-current','true');}button.addEventListener('click',()=>{select.selectedIndex=c.option.index;select.dispatchEvent(new Event('change',{bubbles:true}));panel.close();});list.append(button);});panel.querySelector('.ddi-empty').hidden=matches.length>0;list.scrollTop=0;}
 trigger.addEventListener('click',()=>{search.value='';renderCountries();panel.showModal();search.focus();});
 panel.querySelector('.ddi-close').addEventListener('click',()=>panel.close());
 panel.addEventListener('click',event=>{if(event.target===panel){const r=panel.getBoundingClientRect();if(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom)panel.close();}});
 search.addEventListener('input',renderCountries);select.addEventListener('change',updateTrigger);updateTrigger();

 document.querySelector('#ddi').addEventListener('change', event => {const selected=event.target.selectedOptions[0];const flag=document.querySelector('#flag');flag.src='assets/'+selected.dataset.country.toLowerCase()+'.svg';flag.alt='Bandeira: '+selected.textContent.split(' — ')[1];flag.hidden=false;phone.value = phone.value.replace(/\D/g, '');phone.placeholder = event.target.value === '55' ? '(11) 99999-9999' : 'Telefone com código de área';});
 phone.addEventListener('input', () => {if (document.querySelector('#ddi').value !== '55') return;const n=phone.value.replace(/\D/g,'').slice(0,11);phone.value=n.length>6 ? `(${n.slice(0,2)}) ${n.slice(2,n.length>10?7:6)}-${n.slice(n.length>10?7:6)}` : n.length>2 ? `(${n.slice(0,2)}) ${n.slice(2)}` : n;});
 form.addEventListener('submit', async event => {
  event.preventDefault();const button=form.querySelector('button[type=submit]');const error=document.querySelector('#signup-error');button.disabled=true;button.textContent='Confirmando inscrição…';error.textContent='';
  try {await init;const payload=Object.fromEntries(new FormData(form));await api('signup',payload);markPendingLead();location.assign('/obrigado');}
  catch(err){error.textContent=err.message;}finally{button.disabled=false;button.textContent='Quero participar gratuitamente';}
 });
}
const survey = document.querySelector('#survey');
if (survey) {
 const questions = [
  {title:'Confirme seu melhor e-mail',type:'email'},
  {title:'Qual é o seu sexo?',options:['Feminino','Masculino','Prefiro não informar']},
  {title:'Qual é sua faixa etária?',options:['Menos de 18 anos','De 18 a 25 anos','De 26 a 40 anos','De 41 a 45 anos','De 46 a 60 anos','61 anos ou mais']},
  {title:'Qual é o seu grau de escolaridade?',options:['Ensino fundamental incompleto','Ensino fundamental completo','Ensino médio incompleto','Ensino médio completo','Ensino superior incompleto','Ensino superior completo','Pós-graduação']},
  {title:'Há quanto tempo me acompanha?',options:['Menos de 1 ano','Menos de 2 anos','Acabei de te conhecer','Menos de 6 meses','Mais de 2 anos']},
  {title:'Você já investe?',options:['Sim','Não']},
  {title:'Qual seu nível de conhecimento do mercado financeiro?',options:['Baixo','Já tentei algumas vezes, nunca deu certo','Não tenho conhecimento, sonho em viver de renda','Já sou investidor, quero aprender mais']},
  {title:'Qual é sua profissão atualmente?',options:['Não trabalho no momento / sou estudante','Sou funcionário CLT ou servidor público','Sou autônomo ou profissional liberal (advogado, dentista, consultor etc.)','Sou empreendedor e tenho meu próprio negócio','Trabalho com internet ou marketing digital','Sou empresário e dono de uma empresa consolidada','Sou freelancer ou prestador de serviços','Sou aposentado(a) ou pensionista']},
  {title:'Qual é sua renda mensal?',options:['Não possuo renda mensal','De R$ 1.000,00 a R$ 2.000,00','De R$ 2.001,00 a R$ 3.000,00','De R$ 3.001,00 a R$ 5.000,00','De R$ 5.001,00 a R$ 10.000,00','De R$ 10.001,00 a R$ 20.000,00','Mais de R$ 20.000,00']},
  {title:'Você já é aluno da Thays Assis?',options:['Sim, sou aluno do P24','Sim, sou aluno do Mim Black','Não sou aluno']}
 ];
 let step=0;const answers=Array(10).fill('');let ready=false;let submitting=false;
 const content=document.querySelector('#question');const error=document.querySelector('#survey-error');
 function render(){
  const q=questions[step];document.querySelector('#step-count').textContent=`${step+1} → 10`;document.querySelector('#progress').style.width=`${step*10}%`;content.replaceChildren();
  const h=document.createElement('h2');h.id='question-title';h.textContent=q.title;content.append(h);
  if(q.options){const list=document.createElement('div');list.className='options';q.options.forEach((option,index)=>{const label=document.createElement('label');label.className='option';const radio=document.createElement('input');radio.type='radio';radio.name='answer';radio.value=option;radio.required=true;radio.checked=answers[step]===option;radio.addEventListener('click',()=>{if(!submitting)survey.requestSubmit();});const span=document.createElement('span');span.textContent=String.fromCharCode(65+index)+' · '+option;label.append(radio,span);list.append(label);});content.append(list);}
  else{const input=document.createElement('input');input.name='answer';input.id='answer';input.setAttribute('aria-labelledby','question-title');input.type=q.type;input.required=true;input.value=answers[step];if(q.type==='number'){input.min='1';input.max='120';input.step='1';}else{input.autocomplete='email';input.maxLength=254;}content.append(input);}
  document.querySelector('#next').hidden=!!q.options;document.querySelector('#previous').hidden=step===0;document.querySelector('#next').textContent=step===9?'Concluir check-in':'OK ✓';error.textContent='';
 }
 function save(){const data=new FormData(survey);answers[step]=String(data.get('answer')||'').trim();}
 document.querySelector('#previous').addEventListener('click',()=>{save();step=Math.max(0,step-1);render();});
 survey.addEventListener('submit',async event=>{
  event.preventDefault();if(!ready||submitting)return;save();if(step<9){step++;render();content.querySelector('input')?.focus();return;}
  submitting=true;const next=document.querySelector('#next');next.disabled=true;document.querySelector('#previous').disabled=true;next.textContent='Salvando…';error.textContent='';document.querySelector('#saving').hidden=false;
  try{await api('survey',{answers});document.querySelector('#progress').style.width='100%';survey.hidden=true;document.querySelector('#complete').hidden=false;document.querySelector('#step-count').textContent='Check-in concluído';document.querySelector('#skip-copy').textContent='Agora entre no grupo para receber as informações da aula.';}
  catch(err){error.textContent=err.message;next.hidden=false;}finally{submitting=false;document.querySelector('#saving').hidden=true;next.disabled=false;document.querySelector('#previous').disabled=false;next.textContent='Concluir check-in';}
 });
 init.then(data=>{if(!data.registered){location.replace('/');return;}trackPendingLead();answers[0]=data.email;ready=true;render();if(data.whatsapp){const a=document.querySelector('#whatsapp');a.href=data.whatsapp;a.hidden=false;}else{document.querySelector('#group-unavailable').hidden=false;}if(data.survey_done){survey.hidden=true;document.querySelector('#complete').hidden=false;document.querySelector('#progress').style.width='100%';document.querySelector('#step-count').textContent='Check-in concluído';}}).catch(()=>{content.textContent='Não foi possível carregar seu cadastro. Recarregue a página ou volte para a inscrição.';});
}
