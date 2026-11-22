'use strict';
const assert=require('node:assert/strict');
const History=require('../public/assets/block-editor-history-122.js');
let cases=0;
function test(name,fn){fn();cases++;console.log('PASS '+name);}
test('initial-boundaries',()=>{
 const h=new History('initial');assert.equal(h.canUndo,false);assert.equal(h.canRedo,false);
 assert.equal(h.undo(),null);assert.equal(h.redo(),null);assert.equal(h.current,'initial');
});
test('undo-redo-structural-changes',()=>{
 const h=new History('a');h.record('b');h.record('c');
 assert.equal(h.undo(),'b');assert.equal(h.undo(),'a');assert.equal(h.redo(),'b');assert.equal(h.redo(),'c');
});
test('no-duplicate-input-change-snapshots',()=>{
 const h=new History('a');assert.equal(h.record('a'),false);h.record('b','text',100);
 assert.equal(h.record('b','text',101),false);assert.equal(h.size,2);
});
test('coalesce-same-field-typing',()=>{
 const h=new History('original');h.record('one','text',100);h.record('two','text',300);h.record('three','text',600);
 assert.equal(h.size,2);assert.equal(h.undo(),'original');assert.equal(h.redo(),'three');
});
test('typing-pause-separates-history',()=>{
 const h=new History('a');h.record('b','text',100);h.record('c','text',900);
 assert.equal(h.size,3);assert.equal(h.undo(),'b');
});
test('different-fields-and-focus-boundaries',()=>{
 const h=new History('a');h.record('b','left',100);h.record('c','right',200);h.breakGroup();h.record('d','right',300);
 assert.equal(h.size,4);assert.equal(h.undo(),'c');assert.equal(h.undo(),'b');
});
test('new-edit-discards-redo-branch',()=>{
 const h=new History('a');h.record('b');h.record('c');h.undo();h.record('d');
 assert.equal(h.canRedo,false);assert.equal(h.redo(),null);assert.equal(h.undo(),'b');
});
test('undo-followed-by-typing-does-not-overwrite-prior-entry',()=>{
 const h=new History('a');h.record('b','text',100);h.record('c',null,120);h.undo();h.record('d','text',130);
 assert.equal(h.undo(),'b');assert.equal(h.undo(),'a');
});
test('full-nested-unknown-metadata-preservation',()=>{
 const data={schema:1,blocks:[{type:'columns',data:{columns:[{blocks:[{type:'gallery',data:{images:[{src:'/uploads/x.webp',alt:'عکس فارسی'}],custom:{deep:['keep',1]}}}]},{blocks:[]}]}}]};
 const original=JSON.stringify(data);const h=new History(original);data.blocks=[];h.record(JSON.stringify(data));
 assert.equal(h.undo(),original);assert.deepEqual(JSON.parse(h.current).blocks[0].data.columns[0].blocks[0].data.custom,{deep:['keep',1]});
});
test('at-most-50-undo-transitions',()=>{
 const h=new History('0');for(let i=1;i<=80;i++)h.record(String(i));
 assert.equal(h.size,51);let n=0;while(h.canUndo){h.undo();n++;}assert.equal(n,50);assert.equal(h.current,'30');
});
test('memory-budget-keeps-current-content',()=>{
 const h=new History('a'.repeat(40),{maxBytes:128});h.record('b'.repeat(40));assert.equal(h.size,1);
 h.record('c'.repeat(200));assert.equal(h.current.length,200);assert.equal(h.size,1);
 assert.equal(h.undo(),null);
});
test('history-isolation-between-documents',()=>{
 const a=new History('site-A');const b=new History('site-B');a.record('site-A-new');
 assert.equal(b.current,'site-B');assert.equal(b.canUndo,false);
});
test('invalid-limits-and-values-rejected',()=>{
 assert.throws(()=>new History({}),TypeError);assert.throws(()=>new History('x',{maxEntries:1}),RangeError);
 assert.throws(()=>new History('x',{maxBytes:0}),RangeError);assert.throws(()=>new History('x').record(null),TypeError);
});
console.log('BLOCK_HISTORY_MODEL=PASS cases='+cases+' no_network,no_storage,bounded_memory');
