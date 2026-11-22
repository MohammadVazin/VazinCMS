(()=>{
'use strict';
const form=document.getElementById('page-editor');
if(!form)return;
const legacy=document.getElementById('page-body');
const hidden=form.querySelector('[name="block_document"]');
const root=document.getElementById('block-editor-root');
if(!hidden||!root)return;

let doc={schema:1,blocks:[]};
try{
  const loaded=hidden.value?JSON.parse(hidden.value):null;
  if(loaded&&loaded.schema===1&&Array.isArray(loaded.blocks))doc=loaded;
}catch(_error){}
if(!doc.blocks.length){
  const text=(legacy?.value||'').trim();
  if(text)doc.blocks=text.split(/\n\s*\n/).filter(Boolean)
    .map(t=>({type:'paragraph',data:{text:t}}));
}
let editorDrag=null;
const dragStatus=document.createElement('p');
dragStatus.className='vz-block-drag-status';
dragStatus.setAttribute('role','status');
dragStatus.setAttribute('aria-live','polite');
dragStatus.setAttribute('aria-atomic','true');
root.before(dragStatus);

// History is local to this editor instance and never contains CSRF tokens,
// page metadata or credentials. Missing module falls back to normal editing.
const historyModel=typeof window.VazinBlockHistory==='function'
  ?new window.VazinBlockHistory(JSON.stringify(doc)):null;
let historyUi=null,undoButton=null,redoButton=null,historyStatus=null;
if(historyModel){
  historyUi=document.createElement('div');
  historyUi.className='vz-block-history';
  historyUi.setAttribute('role','group');
  historyUi.setAttribute('aria-label','تاریخچهٔ تغییرات بلوک‌ها');
  historyUi.innerHTML='<div class="vz-history-actions">'+
    '<button type="button" data-block-undo aria-keyshortcuts="Control+Z Meta+Z" disabled>واگرد</button>'+
    '<button type="button" data-block-redo aria-keyshortcuts="Control+Shift+Z Meta+Shift+Z Control+Y" disabled>ازنو</button>'+
    '</div><small>تاریخچه فقط در همین صفحه است؛ ذخیرهٔ خودکار نیست.</small>'+
    '<span class="vz-history-status" role="status" aria-live="polite"></span>';
  root.before(historyUi);
  undoButton=historyUi.querySelector('[data-block-undo]');
  redoButton=historyUi.querySelector('[data-block-redo]');
  historyStatus=historyUi.querySelector('.vz-history-status');
  root.setAttribute('tabindex','-1');
}
function historyControls(){
  if(!historyModel)return;
  undoButton.disabled=!historyModel.canUndo;
  redoButton.disabled=!historyModel.canRedo;
}
function inputIdentity(el){
  const card=el?.closest?.('[data-i]');
  if(!card||!root.contains(el))return null;
  const column=el.closest('[data-col-index]');
  const child=el.closest('[data-child-index]');
  const key=el.dataset.galleryK||el.dataset.childK||el.dataset.k;
  if(!key)return null;
  return {i:card.dataset.i,col:column?.dataset.colIndex??null,
    child:child?.dataset.childIndex??null,key,
    gallery:el.closest('[data-gallery-index]')?.dataset.galleryIndex??null,
    galleryField:Boolean(el.dataset.galleryK),nested:Boolean(el.dataset.childK),start:el.selectionStart,end:el.selectionEnd};
}
function applyHistory(direction){
  if(!historyModel)return false;
  const focus=inputIdentity(document.activeElement);
  const snapshot=direction==='undo'?historyModel.undo():historyModel.redo();
  if(snapshot===null)return false;
  doc=JSON.parse(snapshot);
  render(false);
  if(focus){
    let selector='[data-i="'+focus.i+'"] ';
    if(focus.nested)selector+='[data-col-index="'+focus.col+'"] [data-child-index="'+focus.child+'"] ';
    if(focus.galleryField)selector+='[data-gallery-index="'+focus.gallery+'"] ';
    selector+='['+(focus.galleryField?'data-gallery-k':(focus.nested?'data-child-k':'data-k'))+'="'+CSS.escape(focus.key)+'"]';
    const target=root.querySelector(selector);
    if(target){
      target.focus({preventScroll:true});
      if(typeof focus.start==='number'&&typeof target.setSelectionRange==='function'){
        try{target.setSelectionRange(Math.min(focus.start,target.value.length),Math.min(focus.end??focus.start,target.value.length));}catch(_error){}
      }
    }else root.focus({preventScroll:true});
  }
  historyStatus.textContent=direction==='undo'?'تغییر قبلی بازگردانده شد.':'تغییر دوباره اعمال شد.';
  return true;
}
if(historyModel){
  undoButton.addEventListener('click',()=>applyHistory('undo'));
  redoButton.addEventListener('click',()=>applyHistory('redo'));
  const onHistoryKey=event=>{
    if(event.defaultPrevented||event.isComposing||event.altKey||!(event.ctrlKey||event.metaKey))return;
    if(!root.contains(event.target)&&!historyUi.contains(event.target))return;
    const key=event.key.toLowerCase();
    const direction=key==='z'?(event.shiftKey?'redo':'undo'):
      (key==='y'&&!event.shiftKey?'redo':null);
    if(!direction)return;
    event.preventDefault();applyHistory(direction);
  };
  root.addEventListener('keydown',onHistoryKey);
  historyUi.addEventListener('keydown',onHistoryKey);
  // Native/mobile history events also update the model, rather than
  // leaving hidden block_document out of sync with a textarea.
  root.addEventListener('beforeinput',event=>{
    if(event.isComposing||!['historyUndo','historyRedo'].includes(event.inputType))return;
    if(!event.cancelable)return;
    event.preventDefault();applyHistory(event.inputType==='historyUndo'?'undo':'redo');
  });
  root.addEventListener('focusout',()=>historyModel.breakGroup());
}

const labels={
  paragraph:'متن',heading:'عنوان',list:'فهرست',image:'تصویر',
  gallery:'گالری',button:'دکمه',embed:'جاسازی',code:'کد',columns:'ستون‌ها'
};
function esc(value){
  return String(value??'').replace(/[&<>"']/g,c=>
    ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function columnText(block){
  if(block?.type==='columns'){
    return (Array.isArray(block.data?.columns)?block.data.columns:[])
      .flatMap(c=>Array.isArray(c?.blocks)?c.blocks:[])
      .map(columnText).filter(Boolean).join('\n\n');
  }
  const d=block?.data||{};
  return typeof d.text==='string'?d.text:(typeof d.code==='string'?d.code:'');
}
function sync(){
  const previous=hidden.value;
  hidden.value=JSON.stringify(doc);
  if(legacy)legacy.value=doc.blocks.map(columnText).filter(Boolean).join('\n\n');
  if(previous!==hidden.value)form.dispatchEvent(new CustomEvent('vazin:block-document-change'));
}
function headingLevels(level,key){
  return [2,3,4,5,6].map(v=>
    '<option value="'+v+'"'+(Number(level||2)===v?' selected':'')+'>'+v+'</option>'
  ).join('');
}
function childField(block){
  const d=block?.data||{};
  if(block?.type==='paragraph')
    return '<label>متن ستون<textarea data-child-k="text" rows="3">'+esc(d.text||'')+'</textarea></label>';
  if(block?.type==='heading')
    return '<label>عنوان ستون<input data-child-k="text" value="'+esc(d.text||'')+'"></label>'+
      '<label>سطح عنوان<select data-child-k="level">'+headingLevels(d.level)+'</select></label>';
  return '<p class="vz-col-preserved">این نوع بلوک در این ویرایشگر تغییر نمی‌کند؛ دادهٔ آن هنگام ذخیره حفظ می‌شود.</p>';
}
function columnsField(block){
  const columns=Array.isArray(block.data?.columns)?block.data.columns:[];
  if(columns.length===0){
    return '<div class="vz-columns-empty"><p>هنوز ستونی تعریف نشده است.</p>'+
      '<button type="button" data-init-columns>ایجاد دو ستون</button></div>';
  }
  return '<div class="vz-columns-grid" role="group" aria-label="ویرایش محتوای ستون‌ها">'+
    columns.map((col,colIndex)=>{
      const children=Array.isArray(col?.blocks)?col.blocks:[];
      return '<section class="vz-column" data-col-index="'+colIndex+'">'+
        '<h3>ستون '+(colIndex+1)+'</h3>'+
        children.map((child,childIndex)=>
          '<div class="vz-col-child" data-child-index="'+childIndex+'">'+
            '<div class="vz-col-child-head"><strong>'+esc(labels[child?.type]||child?.type||'بلوک')+'</strong>'+
              '<span class="vz-col-child-actions">'+
                '<button type="button" data-child-drag-handle draggable="true" aria-label="جابجایی بلوک در ستون؛ Space و کلیدهای جهت‌دار" aria-keyshortcuts="Space ArrowUp ArrowDown Escape" aria-pressed="'+(editorDrag?.mode==='keyboard'&&editorDrag.kind==='child'&&editorDrag.topIndex===doc.blocks.indexOf(block)&&editorDrag.colIndex===colIndex&&editorDrag.childIndex===childIndex?'true':'false')+'">⠿</button>'+
                '<button type="button" data-child-up aria-label="بالا بردن بلوک در ستون '+(colIndex+1)+'">↑</button>'+
                '<button type="button" data-child-down aria-label="پایین بردن بلوک در ستون '+(colIndex+1)+'">↓</button>'+
                '<button type="button" data-child-del aria-label="حذف بلوک از ستون '+(colIndex+1)+'">×</button>'+
              '</span></div>'+childField(child)+'</div>'
        ).join('')+
        '<div class="vz-col-add">'+
          '<button type="button" data-add-child="paragraph">افزودن متن</button>'+
          '<button type="button" data-add-child="heading">افزودن عنوان</button>'+
        '</div></section>';
    }).join('')+'</div>';
}
function galleryField(block){
  const data=block?.data||{},images=Array.isArray(data.images)?data.images:[];
  const columns=[2,3,4].includes(Number(data.columns))?Number(data.columns):3;
  const options=[2,3,4].map(n=>'<option value="'+n+'"'+(columns===n?' selected':'')+'>'+n+'</option>').join('');
  const preview=value=>{
    try{const url=new URL(String(value||''),location.origin);return url.origin===location.origin&&!url.username&&!url.password&&url.pathname.startsWith('/uploads/')?url.pathname+url.search:'';}catch(_e){return '';}
  };
  return '<div class="vz-gallery-editor"><label>تعداد ستون در نمایش بزرگ<select data-k="columns">'+options+'</select></label>'+
    '<div class="vz-gallery-items">'+images.map((image,index)=>{
      const src=preview(image?.src);
      return '<article class="vz-gallery-item" data-gallery-index="'+index+'">'+
        (src?'<img src="'+esc(src)+'" alt="'+esc(image?.alt||'')+'" loading="lazy">':'<p>پیش‌نمایش تصویر محلی موجود نیست.</p>')+
        '<label>متن جایگزین تصویر '+(index+1)+'<input data-gallery-k="alt" maxlength="255" value="'+esc(image?.alt||'')+'"></label>'+
        '<label>زیرنویس تصویر '+(index+1)+'<input data-gallery-k="caption" maxlength="500" value="'+esc(image?.caption||'')+'"></label>'+
        '<div class="vz-gallery-actions"><button type="button" data-open-media data-media-gallery-index="'+index+'">جایگزینی تصویر</button>'+
        '<button type="button" data-gallery-up aria-label="بالا بردن تصویر '+(index+1)+'"'+(index===0?' disabled':'')+'>↑</button>'+
        '<button type="button" data-gallery-down aria-label="پایین بردن تصویر '+(index+1)+'"'+(index===images.length-1?' disabled':'')+'>↓</button>'+
        '<button type="button" data-gallery-remove>حذف از گالری</button></div></article>';
    }).join('')+'</div>'+(!images.length?'<p class="muted">هنوز تصویری در گالری نیست.</p>':'')+
    '<button type="button" data-open-media data-media-gallery-index="append"'+(images.length>=100?' disabled':'')+'>افزودن تصویر از کتابخانه</button>'+
    '<small>ترتیب، متن جایگزین و زیرنویس با ذخیرهٔ صفحه اعمال می‌شوند؛ حذف از گالری، فایل کتابخانه را حذف نمی‌کند.</small></div>';
}

function field(block){
  const d=block?.data||{};
  if(block.type==='heading')
    return '<label>متن عنوان<input data-k="text" value="'+esc(d.text||'')+'"></label>'+
      '<label>سطح عنوان<select data-k="level">'+headingLevels(d.level)+'</select></label>';
  if(block.type==='list')
    return '<label>فهرست<textarea data-k="itemsText" rows="4">'+
      esc((Array.isArray(d.items)?d.items:[]).join('\n'))+'</textarea></label>';
  if(block.type==='image')
    return '<button type="button" data-open-media>انتخاب از کتابخانهٔ تصاویر</button><label>مسیر تصویر<input data-k="src" value="'+esc(d.src||'')+'" placeholder="/uploads/..."></label>'+
      '<label>متن جایگزین<input data-k="alt" value="'+esc(d.alt||'')+'"></label>';
  if(block.type==='button')
    return '<label>متن دکمه<input data-k="label" value="'+esc(d.label||'')+'"></label>'+
      '<label>نشانی دکمه<input data-k="url" value="'+esc(d.url||'')+'"></label>';
  if(block.type==='embed')
    return '<label>نشانی جاسازی<input data-k="url" value="'+esc(d.url||'')+'"></label>';
  if(block.type==='code')
    return '<label>کد<textarea data-k="code" rows="5">'+esc(d.code||'')+'</textarea></label>';
  if(block.type==='columns')return columnsField(block);
  if(block.type==='gallery')return galleryField(block);
  return '<label>محتوا<textarea data-k="text" rows="5">'+esc(d.text||'')+'</textarea></label>';
}
function render(trackHistory=true){
  root.innerHTML=doc.blocks.map((block,i)=>
    '<section class="block-card" data-i="'+i+'">'+
      '<header><b>'+esc(labels[block?.type]||block?.type||'بلوک')+'</b><span>'+
        '<button type="button" data-drag-handle draggable="true" aria-label="جابجایی بلوک؛ Space و کلیدهای جهت‌دار" aria-keyshortcuts="Space ArrowUp ArrowDown Escape" aria-pressed="'+(editorDrag?.mode==='keyboard'&&editorDrag.kind==='top'&&editorDrag.index===i?'true':'false')+'">⠿</button>'+
        '<button type="button" data-up aria-label="بالا بردن بلوک">↑</button>'+
        '<button type="button" data-down aria-label="پایین بردن بلوک">↓</button>'+
        '<button type="button" data-del aria-label="حذف بلوک">×</button>'+
      '</span></header>'+field(block)+'</section>'
  ).join('')||'<p class="muted">هنوز بلوکی اضافه نشده است.</p>';
  sync();
  if(trackHistory)historyModel?.record(hidden.value);
  historyControls();
}
function topBlock(target){
  const card=target.closest('.block-card');
  if(!card)return null;
  const i=Number(card.dataset.i);
  return Number.isInteger(i)&&doc.blocks[i]?{i,block:doc.blocks[i]}:null;
}
function colBlocks(target,top){
  const col=target.closest('[data-col-index]');
  if(!col||top.block.type!=='columns')return null;
  const index=Number(col.dataset.colIndex);
  const columns=top.block.data?.columns;
  const current=Array.isArray(columns)?columns[index]:null;
  return Number.isInteger(index)&&Array.isArray(current?.blocks)
    ?{index,blocks:current.blocks}:null;
}
function dragAnnounce(message){dragStatus.textContent=message;}
function dragList(state){
  if(state.kind==='top')return doc.blocks;
  return doc.blocks[state.topIndex]?.data?.columns?.[state.colIndex]?.blocks||null;
}
function finishKeyboardDrag(commit){
  if(!editorDrag||editorDrag.mode!=='keyboard')return;
  const id=editorDrag.kind==='top'?editorDrag.index:
    [editorDrag.topIndex,editorDrag.colIndex,editorDrag.childIndex].join('-');
  if(!commit)doc=JSON.parse(editorDrag.original);
  else{historyModel?.breakGroup();historyModel?.record(hidden.value);historyControls();}
  editorDrag=null;render(false);
  if(commit)dragAnnounce('جابجایی با صفحه‌کلید ثبت شد.');
  else dragAnnounce('جابجایی لغو شد.');
}
root.addEventListener('dragstart',event=>{
  const childHandle=event.target.closest('[data-child-drag-handle]');
  const topHandle=event.target.closest('[data-drag-handle]');
  if(!childHandle&&!topHandle){event.preventDefault();return;}
  if(childHandle){
    const top=topBlock(childHandle),col=top&&colBlocks(childHandle,top),child=childHandle.closest('[data-child-index]');
    if(!top||!col||!child){event.preventDefault();return;}
    editorDrag={mode:'native',kind:'child',topIndex:top.i,colIndex:col.index,childIndex:Number(child.dataset.childIndex)};
  }else{
    const top=topBlock(topHandle);if(!top){event.preventDefault();return;}
    editorDrag={mode:'native',kind:'top',index:top.i};
  }
  event.dataTransfer?.setData('text/plain','vazin-block');
  if(event.dataTransfer)event.dataTransfer.effectAllowed='move';
});
root.addEventListener('dragover',event=>{
  if(!editorDrag||editorDrag.mode!=='native')return;
  const child=event.target.closest('.vz-col-child');
  const column=event.target.closest('.vz-column');
  const card=event.target.closest('.block-card');
  if(editorDrag.kind==='top'&&(!card||child))return;
  if(editorDrag.kind==='child'&&(!column||!topBlock(column)||topBlock(column).block.type!=='columns'))return;
  event.preventDefault();if(event.dataTransfer)event.dataTransfer.dropEffect='move';
  root.querySelectorAll('.vz-drag-over').forEach(node=>node.classList.remove('vz-drag-over'));
  (child||column||card)?.classList.add('vz-drag-over');
});
root.addEventListener('drop',event=>{
  if(!editorDrag||editorDrag.mode!=='native')return;
  event.preventDefault();
  const state=editorDrag;editorDrag=null;
  root.querySelectorAll('.vz-drag-over').forEach(node=>node.classList.remove('vz-drag-over'));
  if(state.kind==='top'){
    const targetCard=event.target.closest('.block-card');
    let target=targetCard?Number(targetCard.dataset.i):doc.blocks.length;
    if(!Number.isInteger(target))return;
    const [moving]=doc.blocks.splice(state.index,1);
    if(targetCard&&state.index<target)target--;
    doc.blocks.splice(Math.max(0,Math.min(target,doc.blocks.length)),0,moving);
    render();dragAnnounce('بلوک با کشیدن و رهاکردن جابه‌جا شد.');return;
  }
  const sourceTop=doc.blocks[state.topIndex],source=sourceTop?.data?.columns?.[state.colIndex]?.blocks;
  const targetColumn=event.target.closest('.vz-column');
  const targetTop=targetColumn?topBlock(targetColumn):null;
  if(!Array.isArray(source)||!targetTop||targetTop.block.type!=='columns')return;
  const targetColIndex=Number(targetColumn.dataset.colIndex);
  const destination=targetTop.block.data?.columns?.[targetColIndex]?.blocks;
  if(!Array.isArray(destination))return;
  const targetChild=event.target.closest('.vz-col-child');
  let targetIndex=targetChild?Number(targetChild.dataset.childIndex):destination.length;
  if(!Number.isInteger(targetIndex))return;
  const [moving]=source.splice(state.childIndex,1);
  if(source===destination&&state.childIndex<targetIndex)targetIndex--;
  destination.splice(Math.max(0,Math.min(targetIndex,destination.length)),0,moving);
  render();dragAnnounce('بلوک تو‌در‌تو با کشیدن و رهاکردن جابه‌جا شد.');
});
root.addEventListener('dragend',()=>{
  if(editorDrag?.mode==='native')editorDrag=null;
  root.querySelectorAll('.vz-drag-over').forEach(node=>node.classList.remove('vz-drag-over'));
});
root.addEventListener('keydown',event=>{
  const childHandle=event.target.closest('[data-child-drag-handle]');
  const topHandle=event.target.closest('[data-drag-handle]');
  if(!childHandle&&!topHandle)return;
  if(event.key===' '||event.key==='Spacebar'){
    event.preventDefault();
    if(editorDrag?.mode==='keyboard'){
      const same=childHandle?editorDrag.kind==='child'&&editorDrag.topIndex===topBlock(childHandle)?.i&&editorDrag.colIndex===colBlocks(childHandle,topBlock(childHandle))?.index&&editorDrag.childIndex===Number(childHandle.closest('[data-child-index]')?.dataset.childIndex):
        editorDrag.kind==='top'&&editorDrag.index===topBlock(topHandle)?.i;
      if(same)finishKeyboardDrag(true);
      return;
    }
    if(editorDrag)return;
    if(childHandle){
      const top=topBlock(childHandle),col=top&&colBlocks(childHandle,top),child=childHandle.closest('[data-child-index]');
      if(!top||!col||!child)return;
      editorDrag={mode:'keyboard',kind:'child',topIndex:top.i,colIndex:col.index,childIndex:Number(child.dataset.childIndex),original:JSON.stringify(doc)};
    }else{
      const top=topBlock(topHandle);if(!top)return;
      editorDrag={mode:'keyboard',kind:'top',index:top.i,original:JSON.stringify(doc)};
    }
    dragAnnounce('بلوک گرفته شد؛ با کلیدهای جهت‌دار جابه‌جا کنید، Space برای ثبت و Escape برای لغو.');
    return;
  }
  if(editorDrag?.mode!=='keyboard')return;
  if(event.key==='Escape'){event.preventDefault();finishKeyboardDrag(false);return;}
  if(event.key!=='ArrowUp'&&event.key!=='ArrowDown')return;
  event.preventDefault();const delta=event.key==='ArrowUp'?-1:1;
  const list=dragList(editorDrag);if(!Array.isArray(list))return;
  const index=editorDrag.kind==='top'?editorDrag.index:editorDrag.childIndex;
  const next=index+delta;if(next<0||next>=list.length)return;
  [list[index],list[next]]=[list[next],list[index]];
  if(editorDrag.kind==='top')editorDrag.index=next;else editorDrag.childIndex=next;
  render(false);
  const selector=editorDrag.kind==='top'?'[data-i="'+next+'"] [data-drag-handle]':
    '[data-i="'+editorDrag.topIndex+'"] [data-col-index="'+editorDrag.colIndex+'"] [data-child-index="'+next+'"] [data-child-drag-handle]';
  root.querySelector(selector)?.focus();
  dragAnnounce('موقعیت بلوک تغییر کرد؛ Space برای ثبت یا Escape برای لغو.');
});
function editValue(event){
  const target=event.target;
  if(!target?.dataset)return;
  const top=topBlock(target);
  if(!top)return;
  if(top.block.type==='gallery'&&target.dataset.galleryK){
    const entry=target.closest('[data-gallery-index]');const index=Number(entry?.dataset.galleryIndex);
    const key=target.dataset.galleryK,images=top.block.data?.images;
    if(!Number.isInteger(index)||!Array.isArray(images)||!images[index]||!['alt','caption'].includes(key))return;
    images[index][key]=target.value;sync();
    historyModel?.record(hidden.value,'gallery:'+top.i+':'+index+':'+key);historyControls();return;
  }
  const key=target.dataset.childK||target.dataset.k;
  if(!key)return;
  let block=top.block;
  if(target.dataset.childK){
    const col=colBlocks(target,top);
    const child=target.closest('[data-child-index]');
    if(!col||!child)return;
    block=col.blocks[Number(child.dataset.childIndex)];
    if(!block||!['paragraph','heading'].includes(block.type))return;
  }
  block.data??={};
  if(key==='itemsText')block.data.items=target.value.split(/\n/).filter(Boolean);
  else if(key==='level'||key==='columns')block.data[key]=Number(target.value);
  else block.data[key]=target.value;
  sync();
  const identity=inputIdentity(target);
  const group=identity&&target.tagName!=='SELECT'
    ?[identity.i,identity.col,identity.child,identity.key].join(':'):null;
  historyModel?.record(hidden.value,group);
  historyControls();
}
root.addEventListener('input',editValue);
root.addEventListener('change',editValue);
root.addEventListener('click',event=>{
  const button=event.target.closest('button');
  if(!button)return;
  const top=topBlock(button);
  if(!top)return;
  if(top.block.type==='gallery'&&button.matches('[data-gallery-up],[data-gallery-down],[data-gallery-remove]')){
    const item=button.closest('[data-gallery-index]'),index=Number(item?.dataset.galleryIndex);
    const images=top.block.data?.images;
    if(!item||!Array.isArray(images)||!Number.isInteger(index)||!images[index])return;
    if(button.hasAttribute('data-gallery-remove'))images.splice(index,1);
    else if(button.hasAttribute('data-gallery-up')&&index>0)[images[index-1],images[index]]=[images[index],images[index-1]];
    else if(button.hasAttribute('data-gallery-down')&&index<images.length-1)[images[index+1],images[index]]=[images[index],images[index+1]];
    else return;
    render();return;
  }
  if(button.hasAttribute('data-init-columns')&&top.block.type==='columns'){
    top.block.data??={};
    if(!Array.isArray(top.block.data.columns)||!top.block.data.columns.length){
      top.block.data.columns=[{blocks:[]},{blocks:[]}];
    }
    render();return;
  }
  const col=colBlocks(button,top);
  if(col){
    const type=button.dataset.addChild;
    if(type==='paragraph'||type==='heading'){
      col.blocks.push({type,data:type==='heading'?{text:'',level:2}:{text:''}});
      const newIndex=col.blocks.length-1;
      render();
      root.querySelector('[data-i="'+top.i+'"] [data-col-index="'+col.index+'"] [data-child-index="'+newIndex+'"] [data-child-k="text"]')?.focus();
      return;
    }
    const child=button.closest('[data-child-index]');
    if(child){
      const index=Number(child.dataset.childIndex);
      if(!Number.isInteger(index)||index<0||index>=col.blocks.length)return;
      if(button.hasAttribute('data-child-del'))col.blocks.splice(index,1);
      else if(button.hasAttribute('data-child-up')&&index>0)
        [col.blocks[index-1],col.blocks[index]]=[col.blocks[index],col.blocks[index-1]];
      else if(button.hasAttribute('data-child-down')&&index<col.blocks.length-1)
        [col.blocks[index+1],col.blocks[index]]=[col.blocks[index],col.blocks[index+1]];
      else return;
      render();return;
    }
  }
  const i=top.i;
  if(button.hasAttribute('data-del'))doc.blocks.splice(i,1);
  else if(button.hasAttribute('data-up')&&i>0)
    [doc.blocks[i-1],doc.blocks[i]]=[doc.blocks[i],doc.blocks[i-1]];
  else if(button.hasAttribute('data-down')&&i<doc.blocks.length-1)
    [doc.blocks[i+1],doc.blocks[i]]=[doc.blocks[i],doc.blocks[i+1]];
  else return;
  render();
});
document.querySelectorAll('[data-add-block]').forEach(button=>
  button.addEventListener('click',()=>{
    const type=button.dataset.addBlock;
    if(!Object.hasOwn(labels,type))return;
    const data=type==='list'?{items:[]}:type==='heading'?{text:'',level:2}:
      type==='gallery'?{images:[],columns:3}:type==='button'?{label:'',url:''}:
      type==='image'?{src:'',alt:''}:type==='embed'?{url:''}:
      type==='code'?{code:''}:type==='columns'?
      {columns:[{blocks:[]},{blocks:[]}]}:{text:''};
    doc.blocks.push({type,data});
    render();
  })
);
// Recovery is a normal undoable editor change. It never submits the form
// or edits slug, locale, SEO or publication controls.
form.addEventListener('vazin:restore-block-document',event=>{
  const value=event.detail?.block_document;
  if(typeof value!=='string'||value.length>1048576)return;
  try{
    const recovered=JSON.parse(value);
    if(recovered?.schema!==1||!Array.isArray(recovered.blocks))return;
    doc=recovered;historyModel?.breakGroup();render();
  }catch(_error){}
});
form.addEventListener('submit',sync);
render(false);
})();
