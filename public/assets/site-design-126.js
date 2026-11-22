(()=>{'use strict';
const form=document.getElementById('site-design-form');
const preview=document.getElementById('design-preview');
if(!form||!preview)return;
const status=document.getElementById('design-preview-status');
const controls=[...form.querySelectorAll('[data-design-key]')];
const initial=Object.fromEntries(controls.map(el=>[el.dataset.designKey,el.value]));
const vars={primary:'--vz-design-primary',background:'--vz-design-bg',surface:'--vz-design-surface',text:'--vz-design-text',content_width:'--vz-design-width',text_size:'--vz-design-font',radius:'--vz-design-radius',spacing:'--vz-design-spacing'};
function paint(){
 for(const el of controls){
  const key=el.dataset.designKey;
  if(key==='header'){preview.dataset.vzDesignHeader=el.value;continue;}
  const value=el.type==='color'?el.value:(String(parseInt(el.value,10))+'px');
  preview.style.setProperty(vars[key],value);
  const label=form.querySelector('[data-color-label="'+key+'"]');if(label)label.textContent=el.value;
 }
 const dirty=controls.some(el=>el.value!==initial[el.dataset.designKey]);
 form.dataset.designDirty=dirty?'1':'0';
 if(status)status.textContent=dirty?'تغییرها فقط در پیش‌نمایش هستند؛ برای اعمال در سایت، ذخیره کنید.':'پیش‌نمایش محلی؛ بدون ذخیره در سرور.';
}
controls.forEach(el=>{el.addEventListener('input',paint);el.addEventListener('change',paint);});
form.querySelectorAll('[data-design-preset]').forEach(button=>button.addEventListener('click',()=>{
 try{
  const values=JSON.parse(button.dataset.designValues);
  controls.forEach(el=>{if(Object.hasOwn(values,el.dataset.designKey))el.value=values[el.dataset.designKey];});
  paint();
 }catch(_){if(status)status.textContent='الگوی طراحی خوانده نشد.';}
}));
form.querySelector('[data-design-revert]')?.addEventListener('click',()=>{controls.forEach(el=>el.value=initial[el.dataset.designKey]);paint();});
document.querySelectorAll('[data-preview-size]').forEach(button=>button.addEventListener('click',()=>{
 preview.dataset.previewSize=button.dataset.previewSize;
 document.querySelectorAll('[data-preview-size]').forEach(b=>b.setAttribute('aria-pressed',b===button?'true':'false'));
}));
form.addEventListener('submit',()=>{form.dataset.designDirty='0';});
paint();
})();
