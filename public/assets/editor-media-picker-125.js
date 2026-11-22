(function(global){
'use strict';
// The first editor picker supports the existing same-origin local library.
// No arbitrary remote URL, HTML, file upload or publication is performed.
function normalizeItem(item,origin){
  if(!item||!Number.isSafeInteger(item.id)||item.id<1||
    !['image/jpeg','image/png','image/webp','image/gif'].includes(item.mime)||
    typeof item.url!=='string'||item.url.length>2048)return null;
  let url;try{url=new URL(item.url,origin);}catch(_error){return null;}
  if(url.origin!==origin||url.username||url.password||
     !url.pathname.startsWith('/uploads/')||!['http:','https:'].includes(url.protocol))return null;
  return {id:item.id,url:url.pathname+url.search,
    name:String(item.name??'تصویر').slice(0,500),alt:String(item.alt??'').slice(0,255),
    width:Number.isInteger(item.width)&&item.width>0?item.width:null,
    height:Number.isInteger(item.height)&&item.height>0?item.height:null};
}
// Pure immutable operation shared by image/gallery picker tests and UI.
function applySelection(value,index,item,slot=null){
  if(value?.schema!==1||!Array.isArray(value.blocks)||!Number.isSafeInteger(index)||
     !Number.isSafeInteger(item?.id)||item.id<1||typeof item.url!=='string')throw Error('invalid_selection');
  const result=JSON.parse(JSON.stringify(value)),block=result.blocks[index];
  if(!block||!['image','gallery'].includes(block.type))throw Error('changed_block');
  const replace=previous=>{
    const next={...previous,src:item.url,alt:item.alt,media_id:item.id};
    if(item.width!==null)next.width=item.width;else delete next.width;
    if(item.height!==null)next.height=item.height;else delete next.height;
    return next;
  };
  if(block.type==='image')block.data=replace(block.data||{});
  else{
    block.data??={};block.data.images??=[];
    const images=block.data.images;if(!Array.isArray(images))throw Error('invalid_gallery');
    if(slot==='append'){
      if(images.length>=100)throw Error('gallery_full');
      images.push(replace({caption:''}));
    }else{
      if(!Number.isInteger(slot)||slot<0||slot>=images.length||!images[slot])throw Error('changed_gallery_item');
      images[slot]=replace(images[slot]);
    }
  }
  return result;
}
if(typeof module==='object'&&module.exports)module.exports={normalizeItem,applySelection};
if(!global.document)return;
const form=document.getElementById('page-editor'),root=document.getElementById('block-editor-root');
if(!form||!root)return;
const hidden=form.querySelector('[name="block_document"]');
if(!hidden)return;
const dialog=document.createElement('dialog');dialog.className='vz-media-picker';
dialog.setAttribute('aria-label','انتخاب تصویر از کتابخانه رسانه');
dialog.innerHTML='<div class="vz-media-head"><h2>کتابخانهٔ تصاویر</h2><button type="button" data-media-close aria-label="بستن کتابخانه تصاویر">بستن</button></div>'+
 '<p>تصویر را انتخاب کنید؛ عنوان جایگزین آن هم وارد بلوک می‌شود. انتشار همچنان به ذخیرهٔ صفحه نیاز دارد.</p>'+
 '<label>جست‌وجوی نام یا متن جایگزین<input type="search" data-media-search autocomplete="off"></label>'+
 '<p role="status" aria-live="polite" data-media-status></p><div class="vz-media-grid" data-media-grid></div>';
form.after(dialog);
const list=dialog.querySelector('[data-media-grid]'),status=dialog.querySelector('[data-media-status]'),search=dialog.querySelector('[data-media-search]');
let items=[],request=null,requestSequence=0,selectedIndex=null,gallerySlot=null,documentBefore='',opener=null;
function close(){
  // Cancel before scheduling the native close event. A rapid reopen must
  // not let the OLD dialog close event abort the NEW library request.
  requestSequence++;request?.abort();request=null;
  if(dialog.open)dialog.close();
}
dialog.querySelector('[data-media-close]').addEventListener('click',close);
dialog.addEventListener('close',()=>{
  if(dialog.open)return;
  requestSequence++;request?.abort();request=null;
  const target=opener?.isConnected?opener:root.querySelector('[data-i="'+selectedIndex+'"] [data-open-media]');
  target?.focus({preventScroll:true});
});
function choose(item){
  if(hidden.value!==documentBefore){dialog.dataset.state='conflict';status.textContent='محتوای ویرایشگر تغییر کرده است؛ کتابخانه را ببندید و دوباره باز کنید.';return;}
  try{
    const documentValue=applySelection(JSON.parse(hidden.value),selectedIndex,item,gallerySlot);
    form.dispatchEvent(new CustomEvent('vazin:restore-block-document',{detail:{block_document:JSON.stringify(documentValue)}}));
    close();
  }catch(_error){dialog.dataset.state='error';status.textContent='این تصویر در بلوک انتخاب‌شده درج نشد. دوباره بلوک تصویر را انتخاب کنید.';}
}
function render(){
  const query=search.value.trim().toLocaleLowerCase();
  const visible=items.filter(item=>(item.name+' '+item.alt).toLocaleLowerCase().includes(query));
  list.replaceChildren();
  for(const item of visible){
    const button=document.createElement('button');button.type='button';button.className='vz-media-item';button.dataset.mediaId=String(item.id);
    const image=document.createElement('img');image.src=item.url;image.alt=item.alt;image.loading='lazy';
    const title=document.createElement('span');title.textContent=item.name;
    const info=document.createElement('small');info.textContent=(item.width&&item.height?item.width+' × '+item.height+' · ':'')+(item.alt||'بدون متن جایگزین');
    button.append(image,title,info);button.addEventListener('click',()=>choose(item));list.append(button);
  }
  status.textContent=visible.length?visible.length+' تصویر قابل انتخاب':(items.length?'تصویری مطابق جست‌وجو پیدا نشد.':'تصویر محلی در کتابخانه موجود نیست؛ ابتدا در بخش رسانه بارگذاری کنید.');
}
search.addEventListener('input',render);
root.addEventListener('click',async event=>{
  const button=event.target.closest('[data-open-media]');if(!button||!root.contains(button))return;
  const card=button.closest('[data-i]');selectedIndex=Number(card?.dataset.i);
  if(!Number.isSafeInteger(selectedIndex)||selectedIndex<0)return;
  gallerySlot=button.dataset.mediaGalleryIndex==='append'?'append':
    (button.hasAttribute('data-media-gallery-index')?Number(button.dataset.mediaGalleryIndex):null);
  opener=button;documentBefore=hidden.value;items=[];search.value='';list.replaceChildren();
  dialog.dataset.state='loading';status.textContent='در حال دریافت کتابخانه…';
  if(typeof dialog.showModal!=='function'){status.textContent='مرورگر از پنجرهٔ انتخاب رسانه پشتیبانی نمی‌کند.';return;}
  if(!dialog.open)dialog.showModal();search.focus();
  request?.abort();request=new AbortController();const current=++requestSequence;
  const timer=setTimeout(()=>request?.abort(),12000);
  try{
    const response=await fetch('/admin/media/picker',{credentials:'same-origin',cache:'no-store',redirect:'error',signal:request.signal,headers:{Accept:'application/json'}});
    if(!response.ok||!response.headers.get('Content-Type')?.includes('application/json'))throw new Error('library_unavailable');
    const data=await response.json();if(!data.ok||!Array.isArray(data.items))throw new Error('library_invalid');
    if(current!==requestSequence||!dialog.open)return;
    items=data.items.slice(0,200).map(item=>normalizeItem(item,location.origin)).filter(Boolean);
    dialog.dataset.state='ready';render();
  }catch(_error){
    if(current!==requestSequence||!dialog.open)return;
    dialog.dataset.state='error';status.textContent='دریافت تصاویر انجام نشد؛ اتصال یا نشست ورود را بررسی کنید. محتوای صفحه تغییر نکرد.';
  }finally{clearTimeout(timer);}
});
})(globalThis);
