'use strict';
// Meta Pixel: PageView nas páginas; Lead somente após cadastro confirmado pela API.
window.m3lTracking = (() => {
 const pixelId = '3570010619958270';
 const receiptKey = 'm3l_meta_lead_receipt';
 const sentKey = 'm3l_meta_lead_sent';
 const memory = {};
 let pendingLead = false;
 let pixelReady = Boolean(window.fbq && window.fbq.callMethod);
 const read = key => {try {return sessionStorage.getItem(key) || memory[key] || '';} catch (_) {return memory[key] || '';}};
 const write = (key, value) => {memory[key] = value;try {sessionStorage.setItem(key, value);} catch (_) {}};
 const newReceipt = () => window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`;

 if (!window.fbq) {
  const fbq = window.fbq = function () {
   fbq.callMethod ? fbq.callMethod.apply(fbq, arguments) : fbq.queue.push(arguments);
  };
  if (!window._fbq) window._fbq = fbq;
  fbq.push = fbq;
  fbq.loaded = true;
  fbq.version = '2.0';
  fbq.queue = [];
 }
 window.fbq('init', pixelId);
 window.fbq('trackSingle', pixelId, 'PageView');

 function sendLead() {
  if (!pendingLead || !pixelReady) return;
  const receipt = read(receiptKey) || newReceipt();
  write(receiptKey, receipt);
  if (read(sentKey) === receipt) return;
  try {
   window.fbq('trackSingle', pixelId, 'Lead', {}, {eventID: receipt});
  } catch (_) {
   return; // Uma falha de mensuração não interrompe o formulário.
  }
  write(sentKey, receipt);
  pendingLead = false;
 }
 if (!pixelReady) {
  const existing = document.querySelector('script[src*="connect.facebook.net/"][src*="fbevents.js"]');
  const script = existing || document.createElement('script');
  script.addEventListener('load', () => {pixelReady = true;sendLead();}, {once:true});
  if (!existing) {
   script.async = true;
   script.src = 'https://connect.facebook.net/en_US/fbevents.js';
   document.head.append(script);
  }
 }
 return {
  registrationSaved() {
   // Uma nova inscrição confirmada pode gerar uma nova conversão.
   write(receiptKey, newReceipt());
  },
  confirmedLead() {
   pendingLead = true;
   sendLead();
  },
 };
})();
