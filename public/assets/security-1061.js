(()=>{
  document.addEventListener('submit',event=>{
    const form=event.target.closest('form[data-confirm]');
    if(form&&!window.confirm(form.dataset.confirm||''))event.preventDefault();
  });
  document.addEventListener('change',event=>{
    const select=event.target.closest('select[data-locale-switch]');
    if(!select||!/^(fa|en|ru|ar)$/.test(select.value))return;
    const parts=location.pathname.split('/');
    parts[1]=select.value;
    const next=parts.join('/')+location.search;
    location.assign(next.startsWith('/')?next:'/'+next);
  });
})();
