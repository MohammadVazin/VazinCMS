(()=>{'use strict';
const form=document.getElementById('site-canvas-form');if(!form)return;
const root=document.getElementById('canvas-tree'),input=form.querySelector('[data-tree-input]'),preview=document.getElementById('canvas-preview'),status=document.getElementById('canvas-announcement');
let tree;try{tree=JSON.parse(input.value);}catch{return;}
let keyboardDragId=null,keyboardOriginal=null,nativeDragId=null;
const shared=JSON.parse(root.dataset.shared||'{}');
const names={section:'سکشن',heading:'عنوان',text:'متن',button:'دکمه',shared:'جزء همگام',page_title:'عنوان اصلی صفحه',page_content:'محتوای اصلی صفحه',archive_title:'عنوان آرشیو',archive_list:'فهرست نوشته‌های منتشرشده'};
const elem=(tag,text)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=text;return e;};
const walk=(xs,fn,parent=null)=>xs.forEach((n,i)=>{fn(n,xs,i,parent);if(n.type==='section')walk(n.children,fn,n);});
const locate=id=>{let result=null;walk(tree,(n,list,i,parent)=>{if(n.id===id)result={n,list,i,parent};});return result;};
const contains=(n,id)=>n.id===id||(n.children||[]).some(c=>contains(c,id));
const id=()=>{const a=new Uint8Array(4);crypto.getRandomValues(a);return 'n-'+[...a].map(v=>v.toString(16).padStart(2,'0')).join('');};
const select=(values,value)=>{const s=elem('select');Object.entries(values).forEach(([v,l])=>{const o=elem('option',l);o.value=v;s.append(o);});s.value=value;return s;};
function field(card,n,key,label,options){const l=elem('label',label);let control;
 if(options)control=select(options,n[key]);else{control=elem(key==='text'?'textarea':'input');control.value=n[key]||'';control.maxLength=key==='href'?240:2000;}
 control.dataset.field=key;control.dataset.id=n.id;l.append(control);card.append(l);
}
function action(label,name,id){const b=elem('button',label);b.type='button';b.dataset.action=name;b.dataset.id=id;return b;}
function sync(){input.value=JSON.stringify(tree);preview.replaceChildren();renderPreview(tree,preview);}
function renderPreview(nodes,parent){nodes.forEach(n=>{let e;
 if(n.type==='section'){e=elem('section');e.className='vz-canvas-section layout-'+n.layout+' tone-'+n.tone+' gap-'+n.gap;renderPreview(n.children,e);}
 else if(n.type==='heading')e=elem('h'+n.level,n.text);
 else if(n.type==='text')e=elem('p',n.text);
 else if(n.type==='button'){e=elem('span',n.text);e.className='vz-canvas-button';}
 else if(n.type==='shared'){e=elem('div','همگام: '+(shared[n.ref]||'انتخاب نشده'));e.className='canvas-slot';}
 else{e=elem('div',names[n.type]);e.className='canvas-slot';}
 parent.append(e);
});}
function render(focus){root.replaceChildren();draw(tree,root);sync();
 if(focus)root.querySelector('[data-action="drag"][data-id="'+focus+'"]')?.focus();
}
function draw(nodes,parent){const list=elem('div');list.className='canvas-node-list';list.dataset.canvasList='1';list.dataset.parentId=parent?.dataset.node||'root';parent.append(list);
 nodes.forEach(n=>{const card=elem('section');card.className='canvas-node';card.dataset.node=n.id;card.draggable=true;
 const bar=elem('div');bar.className='canvas-node-bar';bar.append(elem('strong',names[n.type]),elem('code',n.id));
 const grip=action('⠿','drag',n.id);grip.className='canvas-drag-handle';grip.draggable=true;grip.setAttribute('aria-label','جابجایی '+names[n.type]+'؛ برای گرفتن Space و برای جابه‌جایی کلیدهای جهت‌دار');grip.setAttribute('aria-keyshortcuts','Space ArrowUp ArrowDown Escape');grip.setAttribute('aria-pressed',keyboardDragId===n.id?'true':'false');bar.append(grip);
 ['up','down','indent','outdent'].forEach((a,i)=>bar.append(action(['بالا','پایین','تو‌رفتگی','بیرون‌آوردن'][i],a,n.id)));
 if(!['page_title','page_content','archive_title','archive_list'].includes(n.type))bar.append(action('حذف','remove',n.id));
 card.append(bar);
 if(n.type==='section'){
  field(card,n,'layout','چیدمان',{stack:'عمودی',grid2:'دو ستون',grid3:'سه ستون'});
  field(card,n,'tone','زمینه',{surface:'ساده',muted:'ملایم',accent:'تأکیدی'});
  field(card,n,'gap','فاصله',{compact:'کم',normal:'معمولی',wide:'زیاد'});
  card.append(action('افزودن داخل سکشن','add',n.id));draw(n.children,card);
 }else if(['text','heading','button'].includes(n.type)){
  field(card,n,'text','متن');if(n.type==='heading')field(card,n,'level','سطح عنوان',{2:'۲',3:'۳',4:'۴'});
  if(n.type==='button')field(card,n,'href','پیوند داخلی');
 }else if(n.type==='shared')field(card,n,'ref','جزء همگام',shared);
 const moveBox=elem('div');moveBox.className='canvas-move';const label=elem('label','مقصد جابه‌جایی');
 const targets={'root':'ریشه'};walk(tree,x=>{if(x.type==='section'&&!contains(n,x.id))targets[x.id]='سکشن '+x.id;});
 const chooser=select(targets,'root');chooser.dataset.moveTarget=n.id;label.append(chooser);moveBox.append(label,action('انتقال به مقصد','move',n.id));card.append(moveBox);list.append(card);
 });
}
function create(){const type=document.getElementById('canvas-new-type').value;const node={id:id(),type};
 if(type==='section')Object.assign(node,{layout:'stack',tone:'surface',gap:'normal',children:[]});
 else if(type==='heading')Object.assign(node,{text:'عنوان جدید',level:2});
 else if(type==='text')node.text='متن جدید';
 else if(type==='button')Object.assign(node,{text:'بیشتر بخوانید',href:'/fa'});
 else if(type==='shared'){
   const picker=document.getElementById('canvas-shared-picker');
   node.ref=picker?.value||'';
   if(!node.ref||!Object.prototype.hasOwnProperty.call(shared,node.ref)){status.textContent='ابتدا یک جزء همگام معتبر از کتابخانه انتخاب کنید.';return null;}
 }
 return node;
}
function bounded(){let count=0,depth=0;const scan=(nodes,d)=>nodes.forEach(n=>{count++;depth=Math.max(depth,d);if(n.children)scan(n.children,d+1);});scan(tree,1);return count<=80&&depth<=5;}
function mutate(fn,focus){const old=JSON.stringify(tree);fn();if(!bounded()){tree=JSON.parse(old);status.textContent='حداکثر ۸۰ گره و ۵ سطح مجاز است.';return;}
 render(focus);status.textContent='ساختار تغییر کرد؛ برای اعمال روی سرور ذخیره کنید.';
}
function moveToList(id,dest,index){
 const source=locate(id);if(!source||!Array.isArray(dest))return false;
 if(dest===source.list&&source.i<index)index--;
 source.list.splice(source.i,1);
 dest.splice(Math.max(0,Math.min(index,dest.length)),0,source.n);
 return true;
}
function clearDropMarks(){root.querySelectorAll('.is-drop-target,.is-dragging').forEach(el=>el.classList.remove('is-drop-target','is-dragging'));}
root.addEventListener('dragstart',event=>{
 const handle=event.target.closest('[data-action="drag"]');if(!handle){event.preventDefault();return;}
 nativeDragId=handle.dataset.id;
 const card=handle.closest('.canvas-node');card?.classList.add('is-dragging');
 event.dataTransfer?.setData('text/plain',nativeDragId);
 if(event.dataTransfer)event.dataTransfer.effectAllowed='move';
});
root.addEventListener('dragover',event=>{
 if(!nativeDragId)return;
 const list=event.target.closest('.canvas-node-list');if(!list)return;
 const parentId=list.dataset.parentId||'root';
 const source=locate(nativeDragId);
 if(!source||(parentId!=='root'&&contains(source.n,parentId)))return;
 event.preventDefault();if(event.dataTransfer)event.dataTransfer.dropEffect='move';
 clearDropMarks();list.classList.add('is-drop-target');
 const card=event.target.closest('.canvas-node');if(card&&list.contains(card))card.classList.add('is-drop-target');
});
root.addEventListener('drop',event=>{
 if(!nativeDragId)return;
 const id=nativeDragId;nativeDragId=null;
 const list=event.target.closest('.canvas-node-list');if(!list){clearDropMarks();return;}
 const parentId=list.dataset.parentId||'root';
 const source=locate(id);const targetCard=event.target.closest('.canvas-node');
 if(!source||(parentId!=='root'&&contains(source.n,parentId))){clearDropMarks();return;}
 const dest=parentId==='root'?tree:locate(parentId)?.n.children;
 if(!Array.isArray(dest)){clearDropMarks();return;}
 let index=dest.length;
 if(targetCard&&list.contains(targetCard)){
   const target=locate(targetCard.dataset.node);
   if(target&&target.list===dest){
     const rect=targetCard.getBoundingClientRect();
     index=target.i+(event.clientY>rect.top+rect.height/2?1:0);
   }
 }
 event.preventDefault();clearDropMarks();
 if(moveToList(id,dest,index)){render(id);status.textContent='بخش با کشیدن و رهاکردن جابه‌جا شد.';}
});
root.addEventListener('dragend',()=>{nativeDragId=null;clearDropMarks();});
root.addEventListener('keydown',event=>{
 const handle=event.target.closest('[data-action="drag"]');if(!handle)return;
 const id=handle.dataset.id;
 if(event.key===' '||event.key==='Spacebar'){
   event.preventDefault();
   if(keyboardDragId===id){
     keyboardDragId=null;keyboardOriginal=null;render(id);
     status.textContent='جابجایی صفحه‌کلید ثبت شد.';return;
   }
   if(keyboardDragId!==null)return;
   keyboardDragId=id;keyboardOriginal=JSON.stringify(tree);
   handle.setAttribute('aria-pressed','true');
   status.textContent='بلوک گرفته شد؛ با کلیدهای بالا و پایین جابه‌جا کنید و با Space ثبت یا با Escape لغو کنید.';
   return;
 }
 if(keyboardDragId!==id)return;
 if(event.key==='Escape'){
   event.preventDefault();tree=JSON.parse(keyboardOriginal);keyboardDragId=null;keyboardOriginal=null;render(id);
   status.textContent='جابجایی لغو شد.';return;
 }
 if(event.key==='ArrowUp'||event.key==='ArrowDown'){
   event.preventDefault();const current=locate(id);if(!current)return;
   const next=current.i+(event.key==='ArrowUp'?-1:1);
   if(next<0||next>=current.list.length)return;
   [current.list[current.i],current.list[next]]=[current.list[next],current.list[current.i]];
   render(id);status.textContent='موقعیت بلوک تغییر کرد؛ Space برای ثبت.';
 }
});
const typePicker=document.getElementById('canvas-new-type');
const sharedWrap=document.getElementById('canvas-shared-picker-wrap');
function syncSharedPicker(){if(sharedWrap)sharedWrap.hidden=typePicker?.value!=='shared';}
typePicker?.addEventListener('change',syncSharedPicker);syncSharedPicker();
const pageSelect=document.getElementById('canvas-page-select');
if(pageSelect)pageSelect.addEventListener('change',()=>{const url=new URL(location.href);if(pageSelect.value==='0')url.searchParams.delete('page_id');else url.searchParams.set('page_id',pageSelect.value);location.assign(url.toString());});
document.getElementById('canvas-add-root').addEventListener('click',()=>{const n=create();if(n)mutate(()=>tree.push(n),n.id);});
root.addEventListener('click',e=>{const b=e.target.closest('[data-action]');if(!b||b.dataset.action==='drag')return;const x=locate(b.dataset.id);if(!x)return;const {n,list,i,parent}=x;
 mutate(()=>{switch(b.dataset.action){
 case 'up':if(i>0)[list[i-1],list[i]]=[list[i],list[i-1]];break;
 case 'down':if(i<list.length-1)[list[i+1],list[i]]=[list[i],list[i+1]];break;
 case 'indent':if(i>0&&list[i-1].type==='section'){const dest=list[i-1].children;list.splice(i,1);dest.push(n);}break;
 case 'outdent':if(parent){const p=locate(parent.id);list.splice(i,1);p.list.splice(p.i+1,0,n);}break;
 case 'add':{const child=create();if(child&&n.type==='section')n.children.push(child);break;}
 case 'remove':{const slots=[];walk([n],v=>{if(['page_title','page_content','archive_title','archive_list'].includes(v.type))slots.push(v.id);});if(slots.length){status.textContent='جایگاه محتوای اصلی قابل حذف نیست.';return;}list.splice(i,1);break;}
 case 'move':{const target=root.querySelector('[data-move-target="'+n.id+'"]').value;const dest=target==='root'?tree:locate(target)?.n.children;if(!dest||target===n.id||contains(n,target))return;list.splice(i,1);dest.push(n);break;}
 }},n.id);
});
function update(e){const c=e.target;if(!c.dataset.field)return;const x=locate(c.dataset.id);if(!x)return;x.n[c.dataset.field]=c.dataset.field==='level'?Number(c.value):c.value;sync();}
root.addEventListener('input',update);root.addEventListener('change',update);
form.addEventListener('submit',()=>sync());render();
})();
