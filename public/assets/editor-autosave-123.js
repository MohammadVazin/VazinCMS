(()=>{
'use strict';
const form=document.getElementById('page-editor');if(!form)return;
const root=form.querySelector('#block-editor-root'),hidden=form.querySelector('[name="block_document"]');
const title=form.querySelector('[name="title"]'),body=form.querySelector('[name="body"]');
const base=form.querySelector('[data-editor-base]')?.value||'';
const csrf=form.querySelector('[name="_csrf"]')?.value||'';
const pageId=Number(form.querySelector('[name="id"]')?.value||0);
const draftKey=form.querySelector('[name="draft_key"]')?.value||'';
const draftTokenInput=form.querySelector('[name="draft_token"]');
const fresh=pageId===0;
if(!root||!hidden||!title||!body)return;
const ui=document.createElement('section');ui.className='vz-editor-autosave';
ui.setAttribute('aria-label','ذخیرهٔ موقت و بازیابی محتوا');
ui.innerHTML='<p class="vz-autosave-status" role="status" aria-live="polite"></p>'+
 '<small></small><div class="vz-autosave-recovery" hidden><p>یک نسخهٔ موقت از ویرایش قبلی شما موجود است.</p>'+
 '<button type="button" data-autosave-recover>بازیابی در ویرایشگر</button>'+
 '<button type="button" data-autosave-ignore>ادامه با محتوای فعلی</button></div>';
ui.querySelector('small').textContent=fresh?
 'عنوان، نشانی، زبان، نوع و بلوک‌ها پیش از اولین ذخیره هم حفظ می‌شوند؛ تنظیمات SEO و انتشار جزو نسخهٔ موقت نیستند. نسخهٔ موقت تا ۷ روز قابل بازیابی است؛ سقف هر کاربر ۲۰ نوشتهٔ ذخیره‌نشده است. هنوز صفحه‌ای ساخته یا منتشر نمی‌شود.':
 'نسخهٔ موقتِ عنوان و بلوک‌ها؛ انتشار فقط با «ذخیره صفحه» انجام می‌شود.';
root.before(ui);
const status=ui.querySelector('.vz-autosave-status'),recoveryBox=ui.querySelector('.vz-autosave-recovery');
const setState=(state,text)=>{ui.dataset.state=state;status.textContent=text;};
const snapshot=()=>{
 const x={title:title.value,body:body.value,block_document:hidden.value};
 if(fresh)for(const key of ['slug','locale','content_type'])x[key]=form.querySelector('[name="'+key+'"]')?.value||'';
 return JSON.stringify(x);
};
let lastConfirmed=snapshot(),timer=null,token=draftTokenInput?.value||'',ready=false,busy=false,blocked=false;
let recovery=null,applying=false,submitting=false,pendingSave=null,resubmitting=false;
const original=lastConfirmed;
if(form.hasAttribute('data-save-conflict')){
 setState('conflict','ذخیرهٔ دستی با تعارض روبه‌رو شد. تغییرات این فرم محفوظ‌اند؛ ابتدا با نسخهٔ تازه مقایسه کنید.');
 return;
}
if(!csrf||!Number.isSafeInteger(pageId)||pageId<0||
   (fresh?!/^[a-f0-9]{32}$/.test(draftKey):!/^[a-f0-9]{64}$/.test(base))){
 setState('error','اطلاعات نشست ویرایشگر معتبر نیست؛ صفحه را دوباره باز کنید.');return;
}
if(fresh){
 // Only an opaque draft identity enters the URL; no page content, token,
 // password or document is written to browser local/session storage.
 const url=new URL(location.href);url.searchParams.set('draft',draftKey);
 history.replaceState(history.state,'',url.pathname+url.search+url.hash);
}
async function request(action,payload={}){
 const abort=new AbortController(),timeout=setTimeout(()=>abort.abort(),10000);
 try{
  const params=new URLSearchParams({editor_protocol:fresh?'new-page-v1':'blocks-v1',action,
   page_id:String(pageId),draft_key:draftKey,base_revision:base,_csrf:csrf,...payload});
  const response=await fetch('/admin/pages/autosave',{
   method:'POST',credentials:'same-origin',redirect:'error',cache:'no-store',
   headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:params.toString(),signal:abort.signal
  });
  if(!response.ok){const error=new Error('autosave_http');error.status=response.status;throw error;}
  if(!(response.headers.get('Content-Type')||'').includes('application/json'))throw new Error('autosave_response_type');
  const data=await response.json();
  if(data?.ok!==true||typeof data.autosave_token!=='string'||
     (data.autosave_token!==''&&!/^[a-f0-9]{64}$/.test(data.autosave_token)))throw new Error('autosave_invalid_receipt');
  return data;
 }finally{clearTimeout(timeout);}
}
function assignToken(value){token=value;if(fresh&&draftTokenInput)draftTokenInput.value=value;}
function reportError(error){
 blocked=true;
 if(error.status===409){
  setState('conflict','صفحه یا نسخهٔ موقت در برگهٔ دیگری تغییر کرده یا قبلاً ذخیره شده است. چیزی بازنویسی نشد؛ پیش از ادامه تغییرات را بررسی کنید.');
 }else if(error.status===429){
  setState('error','تعداد نسخه‌های تازهٔ ذخیره‌نشده به سقف رسیده است؛ یکی از نسخه‌های قبلی را به صفحه تبدیل کنید.');
 }else{
  setState('error','نسخهٔ موقت ذخیره نشد؛ تغییرات هنوز در این صفحه‌اند. ذخیرهٔ دستی نیز تعارض نسخه را بررسی می‌کند.');
 }
}
function changed(){
 if(applying||submitting||blocked)return;
 if(timer){clearTimeout(timer);timer=null;}
 if(!ready||recovery||snapshot()===lastConfirmed)return;
 setState('unsaved','تغییرات در انتظار ذخیرهٔ موقت…');timer=setTimeout(save,2000);
}
async function performSave(){
 const sent=snapshot();if(sent===lastConfirmed)return;
 if(sent.length>2200000){reportError(new Error('too_large'));return;}
 busy=true;setState('saving','در حال ذخیرهٔ نسخهٔ موقت…');
 try{
  const result=await request('save',{...JSON.parse(sent),autosave_token:token});
  if(result.saved!==true||result.base_revision!==base||(fresh&&result.draft_key!==draftKey))throw new Error('autosave_invalid_receipt');
  assignToken(result.autosave_token);lastConfirmed=sent;
  setState('saved',fresh?'نسخهٔ موقت روی سرور ذخیره شد؛ هنوز صفحه‌ای ایجاد یا منتشر نشده است.':'نسخهٔ موقت روی سرور ذخیره شد؛ صفحه هنوز منتشر یا به‌روزرسانی نشده است.');
 }catch(error){reportError(error);}
 finally{busy=false;if(!submitting&&!blocked&&snapshot()!==lastConfirmed)changed();}
}
function save(){
 timer=null;if(!ready||recovery||busy||blocked||submitting)return;
 pendingSave=performSave();return pendingSave;
}
form.addEventListener('vazin:block-document-change',changed);
for(const el of [title,...(fresh?['slug','locale','content_type'].map(k=>form.querySelector('[name="'+k+'"]')):[])]){
 if(el){el.addEventListener('input',changed);el.addEventListener('change',changed);}
}
window.addEventListener('pagehide',()=>{submitting=true;if(timer)clearTimeout(timer);});
ui.querySelector('[data-autosave-recover]').addEventListener('click',()=>{
 if(!recovery||blocked)return;
 applying=true;
 try{
  title.value=recovery.title;
  if(fresh)for(const key of ['slug','locale','content_type']){
   const el=form.querySelector('[name="'+key+'"]');if(el&&typeof recovery[key]==='string'){el.value=recovery[key];if(key==='slug'&&el.value.trim())el.dataset.touched='1';}
  }
  form.dispatchEvent(new CustomEvent('vazin:restore-block-document',{detail:{block_document:recovery.block_document}}));
  lastConfirmed=snapshot();recovery=null;recoveryBox.hidden=true;
  setState('recovered','نسخهٔ موقت در ویرایشگر بازیابی شد؛ برای اعمال در سایت «ذخیره صفحه» را بزنید.');
 }finally{applying=false;}
});
ui.querySelector('[data-autosave-ignore]').addEventListener('click',()=>{
 recovery=null;recoveryBox.hidden=true;lastConfirmed=original;
 setState('ready',fresh?'ذخیرهٔ خودکار صفحهٔ تازه فعال است؛ هنوز صفحه‌ای ساخته نشده.':'ذخیرهٔ خودکار عنوان و بلوک‌ها فعال است.');changed();
});
setState('loading','در حال بررسی نسخهٔ موقت قبلی…');
const loaded=request('load').then(data=>{
 if(submitting)return;
 if(data.base_revision!==base||(fresh&&data.draft_key!==draftKey))throw new Error('autosave_revision_mismatch');
 assignToken(data.autosave_token);ready=true;
 if(data.recovery_state==='available'&&data.recovery&&['title','body','block_document'].every(k=>typeof data.recovery[k]==='string')){
  recovery=data.recovery;recoveryBox.hidden=false;
  setState('recovery-available','نسخهٔ موقت قبلی پیدا شد؛ بازیابی فقط با انتخاب شما انجام می‌شود.');
 }else{
  setState('ready',fresh?'ذخیرهٔ خودکار صفحهٔ تازه فعال است؛ هنوز صفحه‌ای ساخته نشده.':'ذخیرهٔ خودکار عنوان و بلوک‌ها فعال است.');changed();
 }
}).catch(reportError);
// Resolve an in-flight autosave before sending the real manual form. This
// carries the latest CAS token and prevents a valid first save racing its
// own autosave. The server still verifies everything independently.
form.addEventListener('submit',async event=>{
 if(resubmitting)return;
 event.preventDefault();if(timer){clearTimeout(timer);timer=null;}
 const submitter=event.submitter;
 await loaded;
 submitting=true;
 if(pendingSave)await pendingSave;
 if(ui.dataset.state==='conflict'){
  submitting=false;return;
 }
 resubmitting=true;
 if(submitter?.form===form)form.requestSubmit(submitter);else form.requestSubmit();
});
})();
