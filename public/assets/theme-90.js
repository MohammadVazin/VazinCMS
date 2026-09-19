document.querySelector('[data-theme-toggle]')?.addEventListener('click',()=>{const e=document.documentElement,d=e.dataset.theme==='dark'?'light':'dark';e.dataset.theme=d;try{localStorage.setItem('vazin-theme',d)}catch{}});
document.querySelector('[data-menu-toggle]')?.addEventListener('click',()=>document.querySelector('[data-site-menu]')?.classList.toggle('open'));
document.querySelectorAll('a[href^="#"]').forEach(a=>a.addEventListener('click',e=>{const t=document.querySelector(a.getAttribute('href'));if(t){e.preventDefault();t.scrollIntoView({behavior:'smooth'})}}));
