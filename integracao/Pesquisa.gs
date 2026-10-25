/** Vincule este script à planilha Pesquisa e publique como aplicativo da Web. */
const SPREADSHEET_ID = '15kcmS2fy6KCpx3Y9etvTGwebr8O4BnBP7WxREWnWUNA';
const TAB_NAME = 'Pesquisa';
const HEADERS = ['Data do envio', 'Nome', 'E-mail do cadastro', 'WhatsApp', 'Melhor e-mail', 'Sexo', 'Idade', 'Escolaridade', 'Há quanto tempo acompanha', 'Já investe', 'Conhecimento do mercado financeiro', 'Profissão', 'Renda mensal', 'Já é aluno', 'ID do envio'];
const UTM_FIELDS = ['utm_source','utm_medium','utm_campaign','utm_content','utm_term'];
const ALL_HEADERS = [...HEADERS, ...UTM_FIELDS];
function doPost(e) {
 const reply = data => ContentService.createTextOutput(JSON.stringify(data)).setMimeType(ContentService.MimeType.JSON);
 let lock;
 try {
  const input = JSON.parse(e.postData.contents);
  const token = PropertiesService.getScriptProperties().getProperty('PESQUISA_TOKEN');
  if (!token || typeof input.token !== 'string' || input.token !== token) return reply({ok:false});
  if (!/^[a-f0-9]{48}$/.test(input.submissionId || '') || !Array.isArray(input.answers) || input.answers.length !== 10 || input.answers.some(a => typeof a !== 'string' || !a.trim() || a.length > 500)) return reply({ok:false});
  const utms = input.utms || {};
  if (typeof utms !== 'object' || Array.isArray(utms) || UTM_FIELDS.some(key => utms[key] != null && (typeof utms[key] !== 'string' || utms[key].length > 1000))) return reply({ok:false});
  lock = LockService.getScriptLock();lock.waitLock(25000);
  const sheet = SpreadsheetApp.openById(SPREADSHEET_ID).getSheetByName(TAB_NAME);
  if (!sheet) throw new Error('Aba não encontrada.');
  if (sheet.getLastRow() === 0) {
   if (sheet.getMaxColumns() < ALL_HEADERS.length) sheet.insertColumnsAfter(sheet.getMaxColumns(), ALL_HEADERS.length-sheet.getMaxColumns());
   sheet.getRange(1,1,1,ALL_HEADERS.length).setValues([ALL_HEADERS]);
   sheet.getRange(1,1,1,HEADERS.length).setFontWeight('bold');sheet.setFrozenRows(1);
  } else if (sheet.getRange(1,1,1,HEADERS.length).getValues()[0].join('|') !== HEADERS.join('|')) {
   throw new Error('Cabeçalhos diferentes. Nenhum dado alterado.');
  }
  // Acrescenta apenas colunas vazias ou já identificadas como UTMs.
  if (sheet.getMaxColumns() < ALL_HEADERS.length) sheet.insertColumnsAfter(sheet.getMaxColumns(), ALL_HEADERS.length-sheet.getMaxColumns());
  const existing = sheet.getRange(1,16,1,UTM_FIELDS.length).getValues()[0];
  if (existing.some((value,index) => value !== '' && value !== UTM_FIELDS[index])) throw new Error('Colunas de UTM ocupadas.');
  sheet.getRange(1,16,1,UTM_FIELDS.length).setValues([UTM_FIELDS]).setFontWeight('bold');
  const safe = value => {
   const text = String(value ?? '').slice(0,1000);
   return /^[=+@\-\t\r\n]/.test(text) ? "'" + text : text;
  };
  const row = [new Date().toISOString(),safe(input.name),safe(input.email),safe(input.phone),...input.answers.map(safe),input.submissionId,...UTM_FIELDS.map(key => safe(utms[key]))];
  const found = sheet.getLastRow() > 1 ? sheet.getRange(2,15,sheet.getLastRow()-1,1).createTextFinder(input.submissionId).matchEntireCell(true).findNext() : null;
  const target = found ? found.getRow() : sheet.getLastRow()+1;
  sheet.getRange(target,1,1,row.length).setNumberFormat('@').setValues([row]);
  SpreadsheetApp.flush();return reply({ok:true});
 } catch(error) {
  console.error('Falha ao gravar pesquisa.');return reply({ok:false});
 } finally {if(lock && lock.hasLock())lock.releaseLock();}
}

