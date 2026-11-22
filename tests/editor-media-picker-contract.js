'use strict';
const assert=require('node:assert/strict');
const {normalizeItem}=require('../public/assets/editor-media-picker-125.js');
let count=0;
const check=(name,fn)=>{fn();count++;console.log('PASS '+name);};
const origin='https://cms.example.test';
const image={id:1,mime:'image/png',name:'عکس فارسی <script>',alt:'توضیح عکس',url:'/uploads/image.png',width:2,height:2};
check('local_image_keeps_name_alt_dimensions',()=>assert.deepEqual(normalizeItem(image,origin),{id:1,name:image.name,alt:image.alt,url:'/uploads/image.png',width:2,height:2}));
check('absolute_same_origin_normalized',()=>assert.equal(normalizeItem({...image,url:origin+'/uploads/image.png'},origin).url,'/uploads/image.png'));
for(const url of ['javascript:alert(1)','data:image/png;base64,AAAA','https://evil.example/uploads/x.png','//evil.example/uploads/x.png','/uploads/../admin/users','/admin/media','https://u:p@cms.example.test/uploads/x.png']){
 check('unsafe_url_denied_'+count,()=>assert.equal(normalizeItem({...image,url},origin),null));
}
check('pdf_not_an_image',()=>assert.equal(normalizeItem({...image,mime:'application/pdf'},origin),null));
check('svg_not_in_image_allowlist',()=>assert.equal(normalizeItem({...image,mime:'image/svg+xml'},origin),null));
check('invalid_media_id_denied',()=>assert.equal(normalizeItem({...image,id:'1'},origin),null));
check('long_metadata_bounded',()=>{const x=normalizeItem({...image,name:'x'.repeat(600),alt:'x'.repeat(300)},origin);assert.equal(x.name.length,500);assert.equal(x.alt.length,255);});
check('bad_dimensions_not_copied',()=>{const x=normalizeItem({...image,width:-1,height:Infinity},origin);assert.equal(x.width,null);assert.equal(x.height,null);});
console.log('EDITOR_MEDIA_PICKER=PASS cases='+count+' same_origin_images,bounded_metadata,no_HTML_interpretation');
